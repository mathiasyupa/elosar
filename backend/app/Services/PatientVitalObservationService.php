<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientVitalObservation;
use App\Models\User;
use App\Services\Obstetrics\ScoreMama;

class PatientVitalObservationService
{
    /** @param array<string, mixed> $vitals */
    public function record(Patient $patient, User $actor, array $vitals): PatientVitalObservation
    {
        $weight = $vitals['weight_kg'] ?? null;
        $height = $vitals['height_cm'] ?? null;
        if ($weight !== null && $height !== null && (float) $weight > 0 && (float) $height > 0) {
            $meters = (float) $height / 100;
            $bmi = round((float) $weight / ($meters ** 2), 2);
            if ($bmi <= 999.99) {
                $vitals['bmi'] = $bmi;
            }
        }

        $vitals = array_merge($vitals, ScoreMama::columnsFor($vitals));

        return PatientVitalObservation::create(array_merge($vitals, [
            'patient_id' => $patient->id,
            'recorded_by_user_id' => $actor->id,
            'recorded_at' => now(),
        ]));
    }
}
