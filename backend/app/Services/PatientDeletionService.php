<?php

namespace App\Services;

use App\Models\Patient;
use App\Support\ActiveWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the two safe outcomes of removing a patient from a workspace list.
 *
 * The active workspace is enforced by Patient's global scope. A physical
 * deletion is allowed only when no clinical, document, appointment, billing,
 * allergy, or family-history trace exists, including soft-deleted traces.
 */
class PatientDeletionService
{
    private const PURGE_CHALLENGE_TTL_SECONDS = 600;

    public function __construct(private readonly ActiveWorkspace $activeWorkspace) {}

    /**
     * @param  list<int>  $ids
     * @return array{deletable: list<array{id:int,name:string}>, archivable: list<array{id:int,name:string}>}
     */
    public function preview(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        $patients = Patient::query()
            ->withExists(Patient::CLINICAL_TRACE_RELATIONS)
            ->whereIn('id', $ids)
            ->get(['id', 'name']);

        // A partial preview is ambiguous and lets a stale or foreign selection
        // look valid. Return the same generic 404 as a direct foreign deletion.
        abort_if($patients->count() !== count($ids), 404);

        $deletable = [];
        $archivable = [];

        foreach ($patients as $patient) {
            $entry = ['id' => $patient->id, 'name' => $patient->name];

            if ($patient->hasClinicalTrace()) {
                $archivable[] = $entry;
            } else {
                $deletable[] = $entry;
            }
        }

        return [
            'deletable' => $deletable,
            'archivable' => $archivable,
        ];
    }

    /**
     * @return array{outcome: 'archived'|'deleted', message: string}
     */
    public function remove(int $id): array
    {
        return DB::transaction(function () use ($id): array {
            // The lock stays held through forceDelete(). A concurrent request
            // cannot add a child through its foreign key after this check and
            // have it cascaded away by a physical deletion.
            $patient = Patient::query()
                ->withExists(Patient::CLINICAL_TRACE_RELATIONS)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($patient->hasClinicalTrace()) {
                $patient->delete();

                return [
                    'outcome' => 'archived',
                    'message' => 'El paciente salió de la lista. Su historia clínica se conserva.',
                ];
            }

            $patient->forceDelete();

            return [
                'outcome' => 'deleted',
                'message' => 'El paciente se eliminó.',
            ];
        });
    }

    /**
     * Count the active workspace's full directory without sending a large list
     * of patient names or IDs to the browser. The challenge binds this exact
     * set to the current owner for a short, deliberate confirmation window.
     *
     * @return array{total:int,deletable:int,blocked:int,challenge:string}
     */
    public function previewPurge(): array
    {
        $patients = $this->patientsWithTraceState()->get();

        return [
            ...$this->purgeSummary($patients),
            'challenge' => $this->makePurgeChallenge($patients),
        ];
    }

    /**
     * Removes an entire newly-created directory only when every current row is
     * trace-free. The decision is deliberately all-or-nothing: archiving a
     * surprise historical row would leave the subsequent re-import blocked by
     * its document, which is not what a "start again" action promises.
     *
     * @return array{state:'purged'|'blocked'|'stale',total:int,deletable:int,blocked:int,deleted?:int}
     */
    public function purgeAll(string $challenge): array
    {
        return DB::transaction(function () use ($challenge): array {
            // Keep the locks through both the retention re-check and force
            // deletion. A concurrent request cannot attach a child after the
            // row was classified and have it silently cascaded away.
            $patients = $this->patientsWithTraceState()
                ->lockForUpdate()
                ->get();
            $summary = $this->purgeSummary($patients);

            if (! $this->hasValidPurgeChallenge($challenge, $patients)) {
                return ['state' => 'stale', ...$summary];
            }

            if ($summary['blocked'] > 0) {
                return ['state' => 'blocked', ...$summary];
            }

            foreach ($patients as $patient) {
                $patient->forceDelete();
            }

            return [
                'state' => 'purged',
                ...$summary,
                'deleted' => $summary['total'],
            ];
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Patient>
     */
    private function patientsWithTraceState(): \Illuminate\Database\Eloquent\Builder
    {
        return Patient::query()
            ->withExists(Patient::CLINICAL_TRACE_RELATIONS)
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return array{total:int,deletable:int,blocked:int}
     */
    private function purgeSummary(Collection $patients): array
    {
        $blocked = $patients->filter(fn (Patient $patient): bool => $patient->hasClinicalTrace())->count();

        return [
            'total' => $patients->count(),
            'deletable' => $patients->count() - $blocked,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param  Collection<int, Patient>  $patients
     */
    private function makePurgeChallenge(Collection $patients): string
    {
        $issuedAt = now()->timestamp;

        return $issuedAt.'.'.$this->purgeChallengeSignature($patients, $issuedAt);
    }

    /**
     * @param  Collection<int, Patient>  $patients
     */
    private function hasValidPurgeChallenge(string $challenge, Collection $patients): bool
    {
        [$issuedAt, $signature] = array_pad(explode('.', $challenge, 2), 2, null);

        if (! is_string($issuedAt) || ! ctype_digit($issuedAt) || ! is_string($signature)) {
            return false;
        }

        $timestamp = (int) $issuedAt;
        $now = now()->timestamp;

        if ($timestamp > $now || $timestamp < $now - self::PURGE_CHALLENGE_TTL_SECONDS) {
            return false;
        }

        return hash_equals($this->purgeChallengeSignature($patients, $timestamp), $signature);
    }

    /**
     * @param  Collection<int, Patient>  $patients
     */
    private function purgeChallengeSignature(Collection $patients, int $issuedAt): string
    {
        $payload = implode('|', [
            'patient-purge-v1',
            (string) ($this->activeWorkspace->id() ?? 0),
            (string) (Auth::id() ?? 0),
            (string) $issuedAt,
            $patients->pluck('id')->implode(','),
        ]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
