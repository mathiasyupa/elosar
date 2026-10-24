<?php

use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The nightly off-site copy.
 *
 * What these pin is not "does it run" but "does it refuse to lie". A backup job
 * that reports success while writing an empty file is worse than no backup at
 * all: it buys months of false confidence and only tells the truth on the day
 * the data is already gone.
 */
beforeEach(function () {
    if (! trim((string) shell_exec('command -v pg_dump'))) {
        $this->markTestSkipped('pg_dump is not installed in this container (it is in the production image).');
    }
});

it('uploads one archive holding both the database and the files', function () {
    Storage::fake('backups');

    $doctor = actingAsDoctor();
    Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $doctor->workspace_id]));

    $this->artisan('elosar:backup', ['--disk' => 'backups'])->assertSuccessful();

    $files = Storage::disk('backups')->files('backups');

    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.tar.gz')
        // Anything near-empty means the dump or the tar silently produced
        // nothing — the failure this whole command is shaped to refuse.
        ->and(Storage::disk('backups')->size($files[0]))->toBeGreaterThan(4096);
})->group('security');

it('keeps only the requested number of copies', function () {
    Storage::fake('backups');

    // Older copies, named the way the command names them (timestamped, so
    // lexical order is chronological order).
    foreach (['2026-08-01_030000', '2026-08-02_030000', '2026-08-03_030000'] as $stamp) {
        Storage::disk('backups')->put("backups/elosar-{$stamp}.tar.gz", str_repeat('x', 5000));
    }

    actingAsDoctor();

    $this->artisan('elosar:backup', ['--disk' => 'backups', '--keep' => 2])->assertSuccessful();

    $files = Storage::disk('backups')->files('backups');

    // Three old + one new, trimmed to the two most recent — and the new one is
    // always among them.
    expect($files)->toHaveCount(2)
        ->and(collect($files)->contains(fn ($f) => str_contains($f, '2026-08-01')))->toBeFalse();
})->group('security');

it('dumps a connection configured as a single URL', function () {
    // Railway hands the database over as one URL, leaving host/port/username at
    // their config defaults (127.0.0.1, root, laravel) while Eloquent works fine
    // off the parsed value. Reading the raw config made pg_dump dial localhost
    // and fail with "connection refused" on a box whose app was talking to the
    // database perfectly — which is exactly how this shipped broken once.
    Storage::fake('backups');

    $live = DB::connection()->getConfig();

    config([
        'database.connections.via_url' => [
            'driver' => 'pgsql',
            'url' => sprintf(
                'postgres://%s:%s@%s:%s/%s',
                $live['username'],
                $live['password'],
                $live['host'],
                $live['port'],
                $live['database'],
            ),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'database.default' => 'via_url',
    ]);

    $this->artisan('elosar:backup', ['--disk' => 'backups'])->assertSuccessful();

    expect(Storage::disk('backups')->files('backups'))->toHaveCount(1);
})->group('security');

it('fails loudly instead of reporting success when the destination is unusable', function () {
    // A disk that does not exist stands in for the real failure modes: wrong
    // credentials, a deleted bucket, an expired key. None of them may end with
    // the command returning success.
    actingAsDoctor();

    $this->artisan('elosar:backup', ['--disk' => 'no-existe-este-disco'])->assertFailed();
})->group('security');
