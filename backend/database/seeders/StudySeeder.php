<?php

namespace Database\Seeders;

use App\Models\Study;
use Illuminate\Database\Seeder;

/**
 * Global orderable-studies catalog for Ecuador: laboratory tests and imaging
 * studies grouped by clinical category, aligned with the MSP HCU order forms.
 *
 * Names are kept in Spanish (clinical content for the market, printed on the
 * patient-facing order — see CLAUDE.md language convention). Clinics may add
 * their own studies on top (workspace_id != null) via the catalog endpoint.
 */
class StudySeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ($this->catalog() as [$type, $category, $names]) {
            foreach ($names as $name) {
                Study::query()->updateOrCreate(
                    ['workspace_id' => null, 'type' => $type, 'name' => $name],
                    [
                        'category'   => $category,
                        'is_active'  => true,
                        'sort_order' => $sort++,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{0:string,1:string,2:list<string>}>
     */
    private function catalog(): array
    {
        $lab = Study::TYPE_LABORATORY;
        $img = Study::TYPE_IMAGING;

        return [
            // ── LABORATORIO ────────────────────────────────────────────────
            [$lab, 'Hematológico', [
                'Biometría hemática completa', 'Hemoglobina', 'Hematocrito', 'Recuento de leucocitos',
                'Recuento de plaquetas', 'Recuento de reticulocitos', 'Eritrosedimentación (VSG)',
                'Grupo sanguíneo y factor Rh', 'Coombs directo', 'Coombs indirecto', 'Gota gruesa (Plasmodium)',
            ]],
            [$lab, 'Hemostasia', [
                'Tiempo de protrombina (TP / INR)', 'Tiempo de tromboplastina parcial (TTP)',
                'Tiempo de coagulación', 'Tiempo de sangría', 'Fibrinógeno',
            ]],
            [$lab, 'Química sanguínea', [
                'Glucosa basal', 'Glucosa postprandial', 'Hemoglobina glicosilada (HbA1c)', 'Urea', 'Creatinina',
                'Ácido úrico', 'Colesterol total', 'Colesterol HDL', 'Colesterol LDL', 'Triglicéridos',
                'Perfil lipídico', 'Proteínas totales', 'Albúmina', 'Bilirrubinas (total y directa)',
            ]],
            [$lab, 'Electrolitos', [
                'Sodio', 'Potasio', 'Cloro', 'Calcio', 'Calcio iónico', 'Fósforo', 'Magnesio',
                'Hierro sérico', 'Capacidad de fijación de hierro (transferrina)',
            ]],
            [$lab, 'Enzimas', [
                'Amilasa', 'Lipasa', 'Colinesterasa', 'CPK', 'CPK-MB', 'Fosfatasa alcalina',
                'Fosfatasa ácida total', 'Fosfatasa ácida prostática', 'GGT', 'TGO (AST)', 'TGP (ALT)',
                'LDH', 'Mioglobina', 'Troponina I',
            ]],
            [$lab, 'Hepatitis', [
                'Hepatitis A (anticuerpos)', 'Hepatitis B (HBsAg)', 'Hepatitis C (anticuerpos)',
            ]],
            [$lab, 'Perfil tiroideo', [
                'TSH', 'T3 libre', 'T3 total', 'T4 libre', 'T4 total', 'Anticuerpos antitiroglobulina',
            ]],
            [$lab, 'Hormonas', [
                'ACTH', 'Beta-HCG cuantitativa', 'Cortisol AM', 'Cortisol PM', 'DHEA', 'Estradiol', 'FSH', 'LH',
                'Prolactina', 'Progesterona', 'Testosterona', 'Insulina', 'Hormona del crecimiento',
            ]],
            [$lab, 'Orina', [
                'Elemental y microscópico de orina (EMO)', 'Depuración de creatinina 24h', 'Proteinuria 24h',
                'Calcio urinario', 'Potasio urinario', 'Ácido vanilmandélico',
            ]],
            [$lab, 'Heces', [
                'Coproparasitario', 'Coprológico', 'Sangre oculta en heces',
            ]],
            [$lab, 'Cultivos y antibiograma', [
                'Urocultivo', 'Hemocultivo', 'Coprocultivo', 'Cultivo de esputo', 'Exudado faríngeo',
                'Cultivo de secreción vaginal', 'Espermograma',
            ]],
            [$lab, 'Inmunología / Serología', [
                'Proteína C reactiva (PCR)', 'Factor reumatoide', 'ASTO', 'VDRL', 'Prueba de VIH',
                'Prueba de embarazo en sangre', 'IgE total',
            ]],

            // ── IMAGENOLOGÍA ───────────────────────────────────────────────
            [$img, 'Radiografía', [
                'Radiografía de tórax', 'Radiografía de abdomen simple', 'Radiografía de columna cervical',
                'Radiografía de columna lumbosacra', 'Radiografía de cráneo', 'Radiografía de pelvis',
                'Radiografía de fémur', 'Radiografía de hombro', 'Enema de colon doble contraste',
                'Histerosalpingografía',
            ]],
            [$img, 'Ecografía', [
                'Ecografía abdominal', 'Ecografía pélvica', 'Ecosonografía transvaginal', 'Ecografía obstétrica',
                'Ecografía de testículos', 'Ecografía de tiroides', 'Ecografía mamaria', 'Ecografía renal y vías urinarias',
            ]],
            [$img, 'Tomografía (TAC)', [
                'TAC de cráneo simple', 'TAC de tórax', 'TAC de abdomen y pelvis',
            ]],
            [$img, 'Resonancia magnética', [
                'Resonancia magnética de cerebro', 'Resonancia magnética de columna', 'Resonancia magnética de rodilla',
            ]],
            [$img, 'Estudios especiales', [
                'Mamografía', 'Densitometría ósea', 'Electrocardiograma (ECG)',
                'Electromiografía y velocidad de conducción', 'Estudio vascular periférico (Doppler arterial)',
                'Endoscopía digestiva alta',
            ]],
        ];
    }
}
