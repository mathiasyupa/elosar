<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordDiagnosis;
use App\Models\RecordPrescription;
use App\Models\StudyOrder;
use App\Models\StudyOrderItem;
use App\Models\Workspace;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The real receta, certificate and study order, drawn with the settings the
 * owner is still editing, for the live preview in Ajustes → Papelería.
 *
 * Nothing here touches the database: the workspace is a CLONE carrying the
 * draft settings, and the patient, record, certificate and order are unsaved
 * models with their relations set by hand. The same services that print the
 * real documents render them, so the preview cannot drift from what prints.
 * Every page carries a MUESTRA watermark and an obviously fictional patient.
 */
class DocumentPreviewService
{
    public function __construct(
        private readonly PrescriptionService $prescriptions,
        private readonly CertificateService $certificates,
        private readonly StudyOrderService $orders,
    ) {}

    /**
     * @param  'prescription'|'certificate'|'order'  $document
     * @param  array<string, mixed>  $draft  Unsaved prescription_settings, merged over the stored ones.
     */
    public function pdf(Workspace $workspace, ?Doctor $doctor, string $document, array $draft): DomPDF
    {
        $preview = clone $workspace;
        $preview->prescription_settings = array_merge($workspace->prescription_settings ?? [], $draft);

        $patient = $this->patient();
        $extra = ['isSample' => true];

        return match ($document) {
            'certificate' => $this->certificates->pdf($this->certificate($preview, $doctor, $patient), $extra),
            'order' => $this->orders->pdf($this->order($preview, $doctor, $patient), $extra),
            default => $this->prescriptions->pdf($this->record($preview, $doctor, $patient), $extra),
        };
    }

    /** Deliberately fictional: the cédula's check digit does not verify. */
    private function patient(): Patient
    {
        $patient = (new Patient)->forceFill([
            'name' => 'María Ejemplo Pérez',
            'id_number' => '0000000000',
            'gender' => 'female',
            'birth_date' => Carbon::now()->subYears(34)->subMonths(3),
            'city' => 'Quito',
            'address' => 'Av. Ejemplo N00-00',
            'phone' => '0990000000',
            'email' => 'ejemplo@correo.ec',
        ]);
        $patient->setRelation('allergies', new Collection);

        return $patient;
    }

    private function record(Workspace $workspace, ?Doctor $doctor, Patient $patient): MedicalRecord
    {
        $record = (new MedicalRecord)->forceFill([
            'reason_for_consultation' => 'Dolor de garganta y fiebre de dos días',
            'diagnosis' => 'Faringoamigdalitis aguda',
            'treatment' => 'Antibiótico por 7 días y analgésico si hay dolor.',
            'recommendations' => 'Abundantes líquidos, reposo relativo, completar el antibiótico.',
            'alarm_signs' => 'Dificultad para respirar o tragar, fiebre que no cede en 48 horas.',
            'created_at' => Carbon::now(),
        ]);

        $record->setRelation('patient', $patient);
        $record->setRelation('doctor', $doctor);
        $record->setRelation('workspace', $workspace);
        $record->setRelation('diagnoses', new Collection([
            (new RecordDiagnosis)->forceFill(['cie10_code' => 'J03.9', 'cie10_description' => 'Amigdalitis aguda, no especificada', 'type' => 'primary']),
        ]));
        $record->setRelation('prescriptions', new Collection([
            (new RecordPrescription)->forceFill([
                'medication_name' => 'Amoxicilina', 'generic_name' => 'Amoxicilina', 'concentration' => '500 mg',
                'dosage' => '1 cápsula', 'route' => 'Oral', 'frequency' => 'cada 8 horas', 'duration_days' => 7,
                'quantity' => 21, 'instructions' => 'Tomar después de las comidas.', 'is_controlled' => false,
            ]),
            (new RecordPrescription)->forceFill([
                'medication_name' => 'Paracetamol', 'generic_name' => 'Paracetamol', 'concentration' => '500 mg',
                'dosage' => '1 tableta', 'route' => 'Oral', 'frequency' => 'cada 8 horas si hay dolor o fiebre', 'duration_days' => 3,
                'quantity' => 9, 'instructions' => null, 'is_controlled' => false,
            ]),
        ]));

        return $record;
    }

    private function certificate(Workspace $workspace, ?Doctor $doctor, Patient $patient): MedicalCertificate
    {
        $today = Carbon::today();

        $certificate = (new MedicalCertificate)->forceFill([
            'type' => MedicalCertificate::TYPE_MEDICAL,
            'title' => 'Certificado médico',
            'issued_at' => $today,
            'attended_at' => $today,
            'valid_from' => $today,
            'valid_until' => $today->copy()->addDays(2),
            'rest_days' => 3,
            'city' => 'Quito',
            'hc_number' => '000000',
            'address' => 'Av. Ejemplo N00-00',
            'phone' => '0990000000',
            'contingency_type' => 'ENFERMEDAD GENERAL',
            'company' => 'Empresa de Ejemplo S.A.',
            'activity' => 'Asistente administrativa',
            'symptoms' => 'Odinofagia, fiebre y malestar general.',
            'diagnosis' => 'Amigdalitis aguda (J03.9)',
            'treatment' => 'Antibiótico y analgésico. Reposo.',
        ]);

        $certificate->setRelation('patient', $patient);
        $certificate->setRelation('doctor', $doctor);
        $certificate->setRelation('workspace', $workspace);
        $certificate->setRelation('medicalRecord', null);

        return $certificate;
    }

    private function order(Workspace $workspace, ?Doctor $doctor, Patient $patient): StudyOrder
    {
        $order = (new StudyOrder)->forceFill([
            'type' => StudyOrder::TYPE_LABORATORY,
            'priority' => 'routine',
            'clinical_info' => 'Control anual. Descartar anemia y alteraciones de la glucosa.',
            'created_at' => Carbon::now(),
        ]);

        $order->setRelation('patient', $patient);
        $order->setRelation('doctor', $doctor);
        $order->setRelation('workspace', $workspace);
        $order->setRelation('medicalRecord', null);
        $order->setRelation('items', new Collection([
            (new StudyOrderItem)->forceFill(['name' => 'Biometría hemática', 'category' => 'Hematología']),
            (new StudyOrderItem)->forceFill(['name' => 'Glucosa en ayunas', 'category' => 'Química sanguínea']),
            (new StudyOrderItem)->forceFill(['name' => 'Perfil lipídico', 'category' => 'Química sanguínea']),
            (new StudyOrderItem)->forceFill(['name' => 'Elemental y microscópico de orina', 'category' => 'Uroanálisis']),
        ]));

        return $order;
    }
}
