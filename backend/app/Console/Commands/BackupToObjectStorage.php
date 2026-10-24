<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Nightly off-site copy of everything that cannot be rebuilt: the database and
 * the files on the persistent volume.
 *
 * Railway backs up nothing on the current plan — not the database, not the
 * volume — so as of today this is the only copy that exists. Even once a managed
 * plan is in place this keeps earning its place, because it lands OUTSIDE the
 * provider: a suspended account or a billing mistake puts Railway's own backups
 * on the wrong side of the fence.
 *
 * Database and files travel in ONE archive on purpose. Backed up separately they
 * drift, and a restore leaves you with a medical record pointing at an X-ray
 * that no longer exists. One artifact, one restore procedure — the difference
 * between a backup you can use at 3am and one you cannot.
 *
 * The archive contains patient data. The destination bucket must be private and
 * its credentials scoped to it alone.
 */
class BackupToObjectStorage extends Command
{
    protected $signature = 'elosar:backup
                            {--keep=14 : Daily copies to retain before pruning}
                            {--disk=s3 : Filesystem disk to upload to}';

    protected $description = 'Dump the database and the storage volume into one archive and upload it off-site';

    /**
     * A correct-looking but empty archive is the classic silent backup failure:
     * everything reports success for months and there is nothing inside. Anything
     * smaller than this means something went wrong upstream.
     */
    private const MINIMUM_PLAUSIBLE_BYTES = 4096;

    public function handle(): int
    {
        $disk = (string) $this->option('disk');
        $workDir = storage_path('app/backup-tmp-'.uniqid());

        if (! mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            $this->error("Could not create the working directory at {$workDir}.");

            return self::FAILURE;
        }

        try {
            $archive = $this->buildArchive($workDir);
            $name = 'backups/elosar-'.now()->format('Y-m-d_His').'.tar.gz';

            $this->uploadArchive($disk, $name, $archive);
            $this->info('Uploaded '.$name.' ('.$this->humanSize(filesize($archive)).').');

            $pruned = $this->pruneOldCopies($disk, (int) $this->option('keep'));

            if ($pruned > 0) {
                $this->line("Pruned {$pruned} older copy(ies).");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // A silent backup failure is indistinguishable from a working backup
            // until the day it matters, so this has to be loud in both channels.
            report($e);
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->deleteDirectory($workDir);
        }
    }

    /** Dumps the database, packs the volume, and combines both into one archive. */
    private function buildArchive(string $workDir): string
    {
        // The RESOLVED connection config, not config('database.connections.*').
        // Railway hands the database over as a single URL, so the individual
        // host/port/username keys sit at their config defaults (127.0.0.1, root,
        // laravel) while Eloquent works fine off the parsed URL. Reading the raw
        // config makes pg_dump dial localhost and fail with "connection refused"
        // on a box whose application is talking to the database perfectly.
        $db = DB::connection()->getConfig();

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (blank($db[$key] ?? null)) {
                throw new RuntimeException(
                    "The database connection has no [{$key}]; cannot dump it. Check DB_URL / DB_* in the environment."
                );
            }
        }

        $dump = $workDir.'/database.sql';

        $this->line('Dumping the database…');
        $this->runProcess(
            ['pg_dump', '--no-owner', '--no-privileges', '--format=plain', '--file='.$dump,
                '--host='.$db['host'], '--port='.(string) $db['port'],
                '--username='.$db['username'], $db['database']],
            // Passed by environment so the password never appears in a process
            // list, where any other process on the box could read it.
            ['PGPASSWORD' => (string) $db['password']],
        );

        $this->assertPlausible($dump, 'The database dump');

        $this->line('Packing the storage volume…');
        $files = $workDir.'/files.tar.gz';
        // Excludes its own working directory, which lives under storage/app.
        $this->runProcess(['tar', '-czf', $files, '--exclude=backup-tmp-*', '-C', storage_path('app'), '.']);

        $archive = $workDir.'/elosar-backup.tar.gz';
        $this->runProcess(['tar', '-czf', $archive, '-C', $workDir, 'database.sql', 'files.tar.gz']);

        $this->assertPlausible($archive, 'The combined archive');

        return $archive;
    }

    private function uploadArchive(string $disk, string $name, string $archive): void
    {
        // Streamed, not read into memory: this archive grows with the clinic and
        // the container's memory does not.
        $stream = fopen($archive, 'rb');

        try {
            if (! Storage::disk($disk)->writeStream($name, $stream)) {
                throw new RuntimeException("The [{$disk}] disk rejected the upload of {$name}.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Trust the destination, not the return value: confirm it is really there
        // and the size survived the trip.
        $uploaded = Storage::disk($disk)->size($name);
        $local = filesize($archive);

        if ($uploaded !== $local) {
            throw new RuntimeException(
                "Uploaded size ({$uploaded} bytes) does not match the local archive ({$local} bytes)."
            );
        }
    }

    /** @return int copies removed */
    private function pruneOldCopies(string $disk, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        // Names are timestamped, so lexical order is chronological order.
        $copies = collect(Storage::disk($disk)->files('backups'))
            ->filter(fn (string $path): bool => str_ends_with($path, '.tar.gz'))
            ->sort()
            ->values();

        $stale = $copies->slice(0, max(0, $copies->count() - $keep));

        foreach ($stale as $path) {
            Storage::disk($disk)->delete($path);
        }

        return $stale->count();
    }

    /**
     * Not `run()`: Illuminate\Console\Command already defines that as public, and
     * PHP will not let a subclass narrow it.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function runProcess(array $command, array $env = []): void
    {
        $process = new Process($command, base_path(), $env ?: null, null, 1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                $command[0].' failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function assertPlausible(string $path, string $what): void
    {
        $size = file_exists($path) ? filesize($path) : 0;

        if ($size < self::MINIMUM_PLAUSIBLE_BYTES) {
            throw new RuntimeException("{$what} came out at {$size} bytes, which cannot be right.");
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
