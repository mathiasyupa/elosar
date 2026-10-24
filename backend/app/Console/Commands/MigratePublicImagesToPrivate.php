<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off move of the images that used to live on the public disk — doctor
 * signatures and photos, patient photos and letterhead art — onto the private
 * disk, where they are only reachable through a signed, expiring URL.
 *
 * Relative paths are preserved, so the `*_path` columns already stored in the
 * database stay valid and no data migration is needed. The command is
 * idempotent: running it twice is a no-op, and a file already present at the
 * destination is left untouched rather than overwritten.
 */
class MigratePublicImagesToPrivate extends Command
{
    protected $signature = 'elosar:migrate-public-images {--dry-run : List what would move without touching anything}';

    protected $description = 'Move signatures, photos and letterhead images from the public disk to the private disk';

    /**
     * Directories seeded by the API and the Filament panel. `doctor-photos` and
     * `doctors/photos` are both here on purpose: ProfileController and
     * DoctorForm historically wrote the doctor photo to different directories.
     */
    private const DIRECTORIES = [
        'signatures',
        'doctor-photos',
        'doctors/photos',
        'patients/photos',
        'letterheads',
    ];

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        $dryRun = (bool) $this->option('dry-run');

        $moved = 0;
        $skipped = 0;

        foreach (self::DIRECTORIES as $directory) {
            foreach ($public->allFiles($directory) as $path) {
                if ($private->exists($path)) {
                    $this->line("  skip (already private): {$path}");
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  would move: {$path}");
                    $moved++;

                    continue;
                }

                $private->writeStream($path, $public->readStream($path));
                $public->delete($path);

                $this->line("  moved: {$path}");
                $moved++;
            }
        }

        $verb = $dryRun ? 'would move' : 'moved';
        $this->info("Done — {$verb} {$moved} file(s), skipped {$skipped}.");

        if (! $dryRun && $moved > 0) {
            $this->warn('Remember: the public/storage symlink must go, or a future upload can silently land back on the public disk.');
        }

        return self::SUCCESS;
    }
}
