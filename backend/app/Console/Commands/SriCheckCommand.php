<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Sri\SriDiagnostics;
use App\Support\ActiveWorkspace;
use Illuminate\Console\Command;

/**
 * Prints the SRI setup diagnosis for a workspace.
 *
 * All the logic lives in SriDiagnostics, which the Ajustes screen calls too —
 * this command is just its terminal rendering. It used to carry its own copy of
 * every check, which meant the doctor's own app could not run the diagnosis the
 * developer relied on.
 *
 *   php artisan sri:check 6           # local checks only
 *   php artisan sri:check 6 --send    # + files a throwaway voucher with the SRI
 */
class SriCheckCommand extends Command
{
    protected $signature = 'sri:check
        {workspace : Workspace ID to diagnose}
        {--send : Also file a throwaway voucher with the SRI (test environment only)}';

    protected $description = 'Diagnose the SRI electronic invoicing setup for a workspace, step by step';

    /** Spanish labels, matching what the Ajustes screen shows. */
    private const LABELS = [
        'fiscal'       => 'Datos fiscales',
        'certificate'  => 'Certificado electrónico',
        'schema'       => 'Esquema del comprobante',
        'signature'    => 'Firma electrónica',
        'connectivity' => 'Conexión con el SRI',
        'probe'        => 'Prueba real contra el SRI',
    ];

    public function handle(SriDiagnostics $diagnostics): int
    {
        $workspace = Workspace::find($this->argument('workspace'));

        if (! $workspace) {
            $this->error('Workspace not found.');

            return self::FAILURE;
        }

        // The CLI has no session, so bind the workspace under diagnosis for the
        // command's lifetime: the tenant scope is fail-closed and the XML
        // builder touches scoped relations.
        app(ActiveWorkspace::class)->bind((int) $workspace->id);

        $this->info("SRI diagnostic for workspace [{$workspace->id}] {$workspace->name}");
        $this->newLine();

        $result = $diagnostics->run($workspace, probe: (bool) $this->option('send'));

        foreach ($result['steps'] as $step) {
            $this->line($this->renderStep($step));
        }

        $this->newLine();

        $failed = collect($result['steps'])->contains(fn (array $s): bool => $s['status'] === 'fail');

        if ($failed) {
            $this->line('<error>Some checks failed.</error>');

            return self::FAILURE;
        }

        $this->line('<info>All checks passed.</info>');

        if (! $result['probed']) {
            $this->line('<comment>The SRI was not probed. Use --send in the test environment to close the loop.</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $step
     */
    private function renderStep(array $step): string
    {
        $icon = match ($step['status']) {
            'ok'    => '<info>✓</info>',
            'fail'  => '<error>✗</error>',
            default => '<comment>–</comment>',
        };

        $line = '  ' . $icon . ' ' . (self::LABELS[$step['key']] ?? $step['key']);

        if (! empty($step['detail'])) {
            $line .= ' — ' . $step['detail'];
        }

        if (! empty($step['error'])) {
            $line .= ' — ' . $this->renderError($step['error']);
        }

        return $line;
    }

    /**
     * @param  array<string,mixed>  $error
     */
    private function renderError(array $error): string
    {
        if (($error['source'] ?? null) === 'sri') {
            return trim(sprintf(
                '[%s] %s %s',
                $error['code'] ?? '?',
                $error['message'] ?? '',
                $error['info'] ?? '',
            ));
        }

        return 'fallo interno · ' . ($error['category'] ?? 'desconocido');
    }
}
