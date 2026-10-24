<?php

namespace Database\Seeders;

use App\Models\Cie10Code;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * ICD-10 (CIE-10) catalog for outpatient care in Ecuador.
 *
 * Prefers the full ~14k official catalog shipped (gzipped) at
 * database/data/cie10.csv.gz — sourced from the DGIS/CEMECE Spanish edition
 * and imported via the `cie10:import` command (which handles dotify, chapter
 * derivation and idempotent upsert). When that file is absent we fall back to
 * the curated subset of frequent primary-care codes below, so a checkout
 * without the data file still seeds a usable catalog.
 *
 * Descriptions stay in Spanish on purpose: this is the official PAHO/WHO
 * Spanish edition, clinician-facing content for the Ecuadorian market.
 */
class Cie10Seeder extends Seeder
{
    public function run(): void
    {
        if ($this->importFullCatalog()) {
            return;
        }

        $this->seedCuratedSubset();
    }

    /**
     * Import the full catalog from the bundled gzipped CSV, reusing the
     * `cie10:import` command. Returns false when the data file is missing.
     */
    private function importFullCatalog(): bool
    {
        // Tests rely on the small, deterministic curated subset below (stable
        // categories, fast). The full 14k catalog is a dev/prod data concern
        // and is covered by its own focused test against the bundled file.
        if (app()->environment('testing')) {
            return false;
        }

        $gzPath = database_path('data/cie10.csv.gz');
        if (! is_file($gzPath)) {
            return false;
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'cie10_').'.csv';
        $in = gzopen($gzPath, 'rb');
        $out = fopen($csvPath, 'wb');
        while (! gzeof($in)) {
            fwrite($out, gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);

        try {
            Artisan::call('cie10:import', [
                'file' => $csvPath,
                '--truncate' => true,
                '--dotify' => true,
            ]);
        } finally {
            @unlink($csvPath);
        }

        return true;
    }

    private function seedCuratedSubset(): void
    {
        $codes = [
            // ─── RESPIRATORIO ──────────────────────────────────────────────
            ['code' => 'J00',   'description' => 'Rinofaringitis aguda (resfriado común)',               'category' => 'Respiratorio'],
            ['code' => 'J01.0', 'description' => 'Sinusitis maxilar aguda',                              'category' => 'Respiratorio'],
            ['code' => 'J02.9', 'description' => 'Faringitis aguda, no especificada',                   'category' => 'Respiratorio'],
            ['code' => 'J03.9', 'description' => 'Amigdalitis aguda, no especificada',                  'category' => 'Respiratorio'],
            ['code' => 'J06.9', 'description' => 'Infección aguda de las vías respiratorias superiores, no especificada', 'category' => 'Respiratorio'],
            ['code' => 'J10.0', 'description' => 'Influenza con neumonía debida a virus de la influenza identificado', 'category' => 'Respiratorio'],
            ['code' => 'J11.0', 'description' => 'Influenza con neumonía, virus no identificado',        'category' => 'Respiratorio'],
            ['code' => 'J18.9', 'description' => 'Neumonía, no especificada',                           'category' => 'Respiratorio'],
            ['code' => 'J20.9', 'description' => 'Bronquitis aguda, no especificada',                   'category' => 'Respiratorio'],
            ['code' => 'J21.9', 'description' => 'Bronquiolitis aguda, no especificada',                'category' => 'Respiratorio'],
            ['code' => 'J40',   'description' => 'Bronquitis, no especificada como aguda o crónica',    'category' => 'Respiratorio'],
            ['code' => 'J41.0', 'description' => 'Bronquitis crónica simple',                           'category' => 'Respiratorio'],
            ['code' => 'J45.9', 'description' => 'Asma, no especificada',                               'category' => 'Respiratorio'],
            ['code' => 'J45.0', 'description' => 'Asma predominantemente alérgica',                    'category' => 'Respiratorio'],

            // ─── DIGESTIVO ─────────────────────────────────────────────────
            ['code' => 'A09',   'description' => 'Diarrea y gastroenteritis de presunto origen infeccioso', 'category' => 'Digestivo'],
            ['code' => 'K21.0', 'description' => 'Enfermedad por reflujo gastroesofágico con esofagitis', 'category' => 'Digestivo'],
            ['code' => 'K21.9', 'description' => 'Enfermedad por reflujo gastroesofágico sin esofagitis', 'category' => 'Digestivo'],
            ['code' => 'K25.9', 'description' => 'Úlcera gástrica, no especificada',                   'category' => 'Digestivo'],
            ['code' => 'K29.7', 'description' => 'Gastritis, no especificada',                         'category' => 'Digestivo'],
            ['code' => 'K37',   'description' => 'Apendicitis, no especificada',                       'category' => 'Digestivo'],
            ['code' => 'K57.3', 'description' => 'Enfermedad diverticular del intestino grueso sin perforación ni absceso', 'category' => 'Digestivo'],
            ['code' => 'K58.9', 'description' => 'Síndrome del intestino irritable sin diarrea',       'category' => 'Digestivo'],
            ['code' => 'K74.6', 'description' => 'Cirrosis hepática, no especificada',                 'category' => 'Digestivo'],
            ['code' => 'K80.2', 'description' => 'Colelitiasis con cólico biliar sin colecistitis',    'category' => 'Digestivo'],
            ['code' => 'K92.1', 'description' => 'Melena',                                             'category' => 'Digestivo'],

            // ─── CARDIOVASCULAR ────────────────────────────────────────────
            ['code' => 'I10',   'description' => 'Hipertensión esencial (primaria)',                    'category' => 'Cardiovascular'],
            ['code' => 'I11.9', 'description' => 'Enfermedad cardíaca hipertensiva sin insuficiencia cardíaca', 'category' => 'Cardiovascular'],
            ['code' => 'I20.9', 'description' => 'Angina de pecho, no especificada',                   'category' => 'Cardiovascular'],
            ['code' => 'I21.9', 'description' => 'Infarto agudo del miocardio, sin otra especificación', 'category' => 'Cardiovascular'],
            ['code' => 'I25.9', 'description' => 'Enfermedad isquémica crónica del corazón, no especificada', 'category' => 'Cardiovascular'],
            ['code' => 'I48',   'description' => 'Fibrilación y aleteo auricular',                     'category' => 'Cardiovascular'],
            ['code' => 'I50.0', 'description' => 'Insuficiencia cardíaca congestiva',                  'category' => 'Cardiovascular'],
            ['code' => 'I63.9', 'description' => 'Infarto cerebral, no especificado',                  'category' => 'Cardiovascular'],
            ['code' => 'I73.9', 'description' => 'Enfermedades vasculares periféricas, no especificadas', 'category' => 'Cardiovascular'],
            ['code' => 'I83.9', 'description' => 'Venas varicosas de extremidades inferiores, sin úlcera ni inflamación', 'category' => 'Cardiovascular'],

            // ─── ENDOCRINO / METABÓLICO ────────────────────────────────────
            ['code' => 'E10.9', 'description' => 'Diabetes mellitus tipo 1, sin complicaciones',       'category' => 'Endocrino'],
            ['code' => 'E11.9', 'description' => 'Diabetes mellitus tipo 2, sin complicaciones',       'category' => 'Endocrino'],
            ['code' => 'E11.0', 'description' => 'Diabetes mellitus tipo 2 con coma',                  'category' => 'Endocrino'],
            ['code' => 'E11.6', 'description' => 'Diabetes mellitus tipo 2 con otras complicaciones especificadas', 'category' => 'Endocrino'],
            ['code' => 'E14.9', 'description' => 'Diabetes mellitus, no especificada, sin complicaciones', 'category' => 'Endocrino'],
            ['code' => 'E03.9', 'description' => 'Hipotiroidismo, no especificado',                    'category' => 'Endocrino'],
            ['code' => 'E05.9', 'description' => 'Tirotoxicosis, no especificada',                     'category' => 'Endocrino'],
            ['code' => 'E66.9', 'description' => 'Obesidad, no especificada',                          'category' => 'Endocrino'],
            ['code' => 'E78.0', 'description' => 'Hipercolesterolemia pura',                           'category' => 'Endocrino'],
            ['code' => 'E78.5', 'description' => 'Hiperlipidemia, no especificada',                    'category' => 'Endocrino'],
            ['code' => 'E87.1', 'description' => 'Hiposmolalidad e hiponatremia',                      'category' => 'Endocrino'],
            ['code' => 'E87.6', 'description' => 'Hipopotasiemia',                                     'category' => 'Endocrino'],

            // ─── MUSCULOESQUELÉTICO ────────────────────────────────────────
            ['code' => 'M10.9', 'description' => 'Gota, no especificada',                              'category' => 'Musculoesquelético'],
            ['code' => 'M15.9', 'description' => 'Poliartrosis, no especificada',                      'category' => 'Musculoesquelético'],
            ['code' => 'M17.9', 'description' => 'Gonartrosis, no especificada',                       'category' => 'Musculoesquelético'],
            ['code' => 'M19.9', 'description' => 'Artrosis, no especificada',                          'category' => 'Musculoesquelético'],
            ['code' => 'M25.5', 'description' => 'Dolor articular',                                    'category' => 'Musculoesquelético'],
            ['code' => 'M47.9', 'description' => 'Espondiloartrosis, no especificada',                 'category' => 'Musculoesquelético'],
            ['code' => 'M51.1', 'description' => 'Degeneración de disco intervertebral lumbar y de otras partes (con radiculopatía)', 'category' => 'Musculoesquelético'],
            ['code' => 'M54.5', 'description' => 'Lumbago no especificado',                            'category' => 'Musculoesquelético'],
            ['code' => 'M54.4', 'description' => 'Lumbago con ciática',                                'category' => 'Musculoesquelético'],
            ['code' => 'M79.3', 'description' => 'Paniculitis, no especificada',                       'category' => 'Musculoesquelético'],

            // ─── NEUROLÓGICO / PSIQUIÁTRICO ────────────────────────────────
            ['code' => 'F32.9', 'description' => 'Episodio depresivo, no especificado',                'category' => 'Psiquiátrico'],
            ['code' => 'F40.1', 'description' => 'Fobias sociales',                                    'category' => 'Psiquiátrico'],
            ['code' => 'F41.1', 'description' => 'Trastorno de ansiedad generalizada',                 'category' => 'Psiquiátrico'],
            ['code' => 'F41.9', 'description' => 'Trastorno de ansiedad, no especificado',             'category' => 'Psiquiátrico'],
            ['code' => 'F51.0', 'description' => 'Insomnio no orgánico',                              'category' => 'Psiquiátrico'],
            ['code' => 'G43.9', 'description' => 'Migraña, no especificada',                           'category' => 'Neurológico'],
            ['code' => 'G44.2', 'description' => 'Cefalea tensional',                                  'category' => 'Neurológico'],
            ['code' => 'G45.9', 'description' => 'Accidente isquémico cerebral transitorio, no especificado', 'category' => 'Neurológico'],
            ['code' => 'G47.0', 'description' => 'Trastornos del inicio y del mantenimiento del sueño [insomnios]', 'category' => 'Neurológico'],

            // ─── UROLÓGICO / RENAL ─────────────────────────────────────────
            ['code' => 'N18.9', 'description' => 'Insuficiencia renal crónica, no especificada',       'category' => 'Urológico'],
            ['code' => 'N20.0', 'description' => 'Cálculo del riñón',                                  'category' => 'Urológico'],
            ['code' => 'N20.1', 'description' => 'Cálculo del uréter',                                 'category' => 'Urológico'],
            ['code' => 'N23',   'description' => 'Cólico renal, no especificado',                      'category' => 'Urológico'],
            ['code' => 'N30.0', 'description' => 'Cistitis aguda',                                     'category' => 'Urológico'],
            ['code' => 'N39.0', 'description' => 'Infección de vías urinarias, sitio no especificado', 'category' => 'Urológico'],
            ['code' => 'N40',   'description' => 'Hiperplasia de la próstata',                         'category' => 'Urológico'],

            // ─── GINECOLÓGICO / OBSTÉTRICO ─────────────────────────────────
            ['code' => 'N80.9', 'description' => 'Endometriosis, no especificada',                     'category' => 'Ginecológico'],
            ['code' => 'N83.2', 'description' => 'Otros quistes ováricos y los no especificados',      'category' => 'Ginecológico'],
            ['code' => 'N91.2', 'description' => 'Amenorrea, no especificada',                         'category' => 'Ginecológico'],
            ['code' => 'N92.0', 'description' => 'Menstruación excesiva y frecuente con ciclo regular', 'category' => 'Ginecológico'],
            ['code' => 'N94.6', 'description' => 'Dismenorrea, no especificada',                       'category' => 'Ginecológico'],
            ['code' => 'O09.9', 'description' => 'Duración del embarazo, no especificada',             'category' => 'Obstétrico'],
            ['code' => 'O80',   'description' => 'Parto único espontáneo, no especificado',            'category' => 'Obstétrico'],

            // ─── DERMATOLÓGICO ─────────────────────────────────────────────
            ['code' => 'B35.9', 'description' => 'Dermatofitosis, no especificada',                    'category' => 'Dermatológico'],
            ['code' => 'L20.9', 'description' => 'Dermatitis atópica, no especificada',                'category' => 'Dermatológico'],
            ['code' => 'L30.9', 'description' => 'Dermatitis, no especificada',                        'category' => 'Dermatológico'],
            ['code' => 'L50.9', 'description' => 'Urticaria, no especificada',                         'category' => 'Dermatológico'],
            ['code' => 'L70.0', 'description' => 'Acné vulgar',                                        'category' => 'Dermatológico'],

            // ─── INFECCIOSAS / PARASITARIAS ────────────────────────────────
            ['code' => 'A15.0', 'description' => 'Tuberculosis del pulmón, con confirmación por microscopía del esputo sin cultivo', 'category' => 'Infeccioso'],
            ['code' => 'A90',   'description' => 'Dengue clásico',                                     'category' => 'Infeccioso'],
            ['code' => 'A91',   'description' => 'Fiebre de dengue hemorrágico',                       'category' => 'Infeccioso'],
            ['code' => 'B01.9', 'description' => 'Varicela sin complicaciones',                        'category' => 'Infeccioso'],
            ['code' => 'B34.9', 'description' => 'Infección viral, no especificada',                   'category' => 'Infeccioso'],

            // ─── OJOS ──────────────────────────────────────────────────────
            ['code' => 'H10.9', 'description' => 'Conjuntivitis, no especificada',                     'category' => 'Oftalmológico'],
            ['code' => 'H25.9', 'description' => 'Catarata senil, no especificada',                    'category' => 'Oftalmológico'],
            ['code' => 'H40.9', 'description' => 'Glaucoma, no especificado',                          'category' => 'Oftalmológico'],
            ['code' => 'H52.1', 'description' => 'Miopía',                                             'category' => 'Oftalmológico'],
            ['code' => 'H52.4', 'description' => 'Presbicia',                                          'category' => 'Oftalmológico'],

            // ─── OÍDO ──────────────────────────────────────────────────────
            ['code' => 'H65.9', 'description' => 'Otitis media no supurativa, no especificada',        'category' => 'Otológico'],
            ['code' => 'H66.9', 'description' => 'Otitis media supurativa, no especificada',           'category' => 'Otológico'],
            ['code' => 'H81.0', 'description' => 'Enfermedad de Ménière',                              'category' => 'Otológico'],

            // ─── ONCOLÓGICO ────────────────────────────────────────────────
            ['code' => 'C34.9', 'description' => 'Tumor maligno del bronquio y del pulmón, no especificado', 'category' => 'Oncológico'],
            ['code' => 'C50.9', 'description' => 'Tumor maligno de la mama, no especificado',          'category' => 'Oncológico'],
            ['code' => 'C61',   'description' => 'Tumor maligno de la próstata',                       'category' => 'Oncológico'],
            ['code' => 'C67.9', 'description' => 'Tumor maligno de la vejiga urinaria, no especificado', 'category' => 'Oncológico'],

            // ─── HEMATOLÓGICO ──────────────────────────────────────────────
            ['code' => 'D50.9', 'description' => 'Anemia por deficiencia de hierro, no especificada',  'category' => 'Hematológico'],
            ['code' => 'D64.9', 'description' => 'Anemia, no especificada',                            'category' => 'Hematológico'],

            // ─── TRAUMATOLÓGICO / LESIONES ─────────────────────────────────
            ['code' => 'S00.9', 'description' => 'Traumatismo superficial de cabeza, no especificado', 'category' => 'Traumatológico'],
            ['code' => 'S09.9', 'description' => 'Traumatismo de la cabeza, no especificado',          'category' => 'Traumatológico'],
            ['code' => 'S20.9', 'description' => 'Traumatismo superficial del tórax, no especificado', 'category' => 'Traumatológico'],
            ['code' => 'S30.9', 'description' => 'Traumatismo superficial del abdomen, dorso y pelvis, no especificado', 'category' => 'Traumatológico'],
            ['code' => 'S60.9', 'description' => 'Traumatismo superficial de la muñeca y de la mano, no especificado', 'category' => 'Traumatológico'],
            ['code' => 'S80.9', 'description' => 'Traumatismo superficial de la pierna, no especificado', 'category' => 'Traumatológico'],
            ['code' => 'T14.9', 'description' => 'Traumatismo, no especificado',                       'category' => 'Traumatológico'],

            // ─── ADMINISTRATIVOS / PREVENTIVOS (Z-codes) ──────────────────
            ['code' => 'Z00.0', 'description' => 'Examen médico general',                              'category' => 'Preventivo'],
            ['code' => 'Z00.1', 'description' => 'Control de salud del niño',                          'category' => 'Preventivo'],
            ['code' => 'Z00.3', 'description' => 'Examen de adolescente',                              'category' => 'Preventivo'],
            ['code' => 'Z01.0', 'description' => 'Examen de los ojos y de la visión',                  'category' => 'Preventivo'],
            ['code' => 'Z01.1', 'description' => 'Examen de los oídos y de la audición',               'category' => 'Preventivo'],
            ['code' => 'Z01.3', 'description' => 'Examen de la tensión arterial',                      'category' => 'Preventivo'],
            ['code' => 'Z03.8', 'description' => 'Observación y evaluación médica por otras razones sospechadas', 'category' => 'Preventivo'],
            ['code' => 'Z04.9', 'description' => 'Examen y observación por otras razones especificadas', 'category' => 'Preventivo'],
            ['code' => 'Z12.1', 'description' => 'Examen de detección de neoplasia maligna del intestino', 'category' => 'Preventivo'],
            ['code' => 'Z12.3', 'description' => 'Examen de detección de neoplasia maligna de la mama', 'category' => 'Preventivo'],
            ['code' => 'Z13.6', 'description' => 'Examen de detección de enfermedades cardiovasculares', 'category' => 'Preventivo'],
            ['code' => 'Z29.2', 'description' => 'Otra quimioprofilaxis',                              'category' => 'Preventivo'],
            ['code' => 'Z34.9', 'description' => 'Supervisión de embarazo normal, no especificado',    'category' => 'Preventivo'],
            ['code' => 'Z36',   'description' => 'Control prenatal del feto',                          'category' => 'Preventivo'],
            ['code' => 'Z39.1', 'description' => 'Atención y examen de la madre durante la lactancia', 'category' => 'Preventivo'],
            ['code' => 'Z76.0', 'description' => 'Expedición de receta repetida',                      'category' => 'Preventivo'],
            ['code' => 'Z76.2', 'description' => 'Solicitud de atención médica de persona sana',       'category' => 'Preventivo'],

            // ─── SEGUIMIENTO / CONTROL CRÓNICO ────────────────────────────
            ['code' => 'Z87.3', 'description' => 'Historia personal de enfermedades del sistema musculoesquelético y del tejido conjuntivo', 'category' => 'Seguimiento'],
            ['code' => 'Z87.7', 'description' => 'Historia personal de malformaciones congénitas y deformidades', 'category' => 'Seguimiento'],
            ['code' => 'Z96.1', 'description' => 'Presencia de lente intraocular',                     'category' => 'Seguimiento'],

            // ─── PEDIATRÍA ─────────────────────────────────────────────────
            ['code' => 'A08.0', 'description' => 'Enteritis debida a rotavirus',                       'category' => 'Pediatría'],
            ['code' => 'J12.9', 'description' => 'Neumonía viral, no especificada',                    'category' => 'Pediatría'],
            ['code' => 'P07.1', 'description' => 'Otro peso bajo al nacer',                            'category' => 'Pediatría'],
            ['code' => 'Q65.9', 'description' => 'Deformidad congénita de la cadera, no especificada', 'category' => 'Pediatría'],
            ['code' => 'R62.0', 'description' => 'Demora en el logro de hitos del desarrollo',         'category' => 'Pediatría'],

            // ─── SÍNTOMAS INESPECÍFICOS ────────────────────────────────────
            ['code' => 'R00.0', 'description' => 'Taquicardia, no especificada',                       'category' => 'Síntomas'],
            ['code' => 'R00.1', 'description' => 'Bradicardia, no especificada',                       'category' => 'Síntomas'],
            ['code' => 'R05',   'description' => 'Tos',                                                'category' => 'Síntomas'],
            ['code' => 'R06.0', 'description' => 'Disnea',                                             'category' => 'Síntomas'],
            ['code' => 'R07.4', 'description' => 'Dolor torácico, no especificado',                    'category' => 'Síntomas'],
            ['code' => 'R10.0', 'description' => 'Dolor abdominal agudo',                              'category' => 'Síntomas'],
            ['code' => 'R10.4', 'description' => 'Otros dolores abdominales y los no especificados',   'category' => 'Síntomas'],
            ['code' => 'R11',   'description' => 'Náusea y vómitos',                                   'category' => 'Síntomas'],
            ['code' => 'R19.4', 'description' => 'Cambios en el hábito intestinal',                    'category' => 'Síntomas'],
            ['code' => 'R25.2', 'description' => 'Calambre y espasmo',                                 'category' => 'Síntomas'],
            ['code' => 'R42',   'description' => 'Mareo y desvanecimiento',                            'category' => 'Síntomas'],
            ['code' => 'R50.9', 'description' => 'Fiebre, no especificada',                            'category' => 'Síntomas'],
            ['code' => 'R51',   'description' => 'Cefalea',                                            'category' => 'Síntomas'],
            ['code' => 'R52.9', 'description' => 'Dolor, no especificado',                             'category' => 'Síntomas'],
            ['code' => 'R53',   'description' => 'Malestar y fatiga',                                  'category' => 'Síntomas'],
            ['code' => 'R55',   'description' => 'Síncope y colapso',                                  'category' => 'Síntomas'],
            ['code' => 'R60.9', 'description' => 'Edema, no especificado',                             'category' => 'Síntomas'],
            ['code' => 'R73.0', 'description' => 'Glucosa anormal en prueba de tolerancia',            'category' => 'Síntomas'],
            ['code' => 'R83.9', 'description' => 'Hallazgo anormal en líquido cefalorraquídeo',        'category' => 'Síntomas'],

            // ─── SÍNDROMES (consulta frecuente) ─────────────────────────────
            ['code' => 'G93.1', 'description' => 'Lesión anóxica del encéfalo, no clasificada en otra parte', 'category' => 'Síndromes'],
            ['code' => 'G93.2', 'description' => 'Síndrome de hipertensión intracraneal benigna',     'category' => 'Síndromes'],
            ['code' => 'G90.0', 'description' => 'Disreflexia autonómica',                             'category' => 'Síndromes'],
            ['code' => 'G90.2', 'description' => 'Síndrome de Horner',                                 'category' => 'Síndromes'],
            ['code' => 'G56.0', 'description' => 'Síndrome del túnel carpiano',                        'category' => 'Síndromes'],
            ['code' => 'G57.0', 'description' => 'Trastornos del nervio ciático',                      'category' => 'Síndromes'],
            ['code' => 'G47.3', 'description' => 'Síndrome de apnea del sueño',                        'category' => 'Síndromes'],
            ['code' => 'E22.0', 'description' => 'Síndrome de acromegalia',                            'category' => 'Síndromes'],
            ['code' => 'E24.2', 'description' => 'Síndrome de Cushing',                                'category' => 'Síndromes'],
            ['code' => 'E66.8', 'description' => 'Otros tipos de obesidad',                            'category' => 'Síndromes'],
            ['code' => 'E88.9', 'description' => 'Trastorno metabólico, no especificado',              'category' => 'Síndromes'],
            ['code' => 'I50.9', 'description' => 'Insuficiencia cardíaca, no especificada',            'category' => 'Síndromes'],
            ['code' => 'I49.9', 'description' => 'Arritmia cardíaca, no especificada',                 'category' => 'Síndromes'],
            ['code' => 'J44.9', 'description' => 'Enfermedad pulmonar obstructiva crónica, no especificada', 'category' => 'Síndromes'],
            ['code' => 'J80',   'description' => 'Síndrome de dificultad respiratoria aguda del adulto', 'category' => 'Síndromes'],
            ['code' => 'J98.4', 'description' => 'Otros trastornos del pulmón',                        'category' => 'Síndromes'],
            ['code' => 'K59.1', 'description' => 'Diarrea funcional',                                  'category' => 'Síndromes'],
            ['code' => 'K91.5', 'description' => 'Síndrome poscolecistectomía',                        'category' => 'Síndromes'],
            ['code' => 'M06.9', 'description' => 'Artritis reumatoide, no especificada',               'category' => 'Síndromes'],
            ['code' => 'M35.2', 'description' => 'Síndrome seco de Sjögren',                           'category' => 'Síndromes'],
            ['code' => 'M35.3', 'description' => 'Polimialgia reumática',                              'category' => 'Síndromes'],
            ['code' => 'M79.7', 'description' => 'Fibromialgia',                                       'category' => 'Síndromes'],
            ['code' => 'M79.1', 'description' => 'Mialgia',                                            'category' => 'Síndromes'],
            ['code' => 'N17.9', 'description' => 'Insuficiencia renal aguda, no especificada',         'category' => 'Síndromes'],
            ['code' => 'N39.3', 'description' => 'Incontinencia urinaria de esfuerzo',                 'category' => 'Síndromes'],
            ['code' => 'P95',   'description' => 'Síndrome de muerte fetal de causa no especificada',  'category' => 'Síndromes'],
            ['code' => 'Q87.1', 'description' => 'Síndromes de malformaciones congénitas',             'category' => 'Síndromes'],
            ['code' => 'Q87.4', 'description' => 'Síndrome de Marfan',                                 'category' => 'Síndromes'],
            ['code' => 'Q90.9', 'description' => 'Síndrome de Down, no especificado',                  'category' => 'Síndromes'],
            ['code' => 'R57.0', 'description' => 'Choque cardiogénico',                                'category' => 'Síndromes'],
            ['code' => 'R57.1', 'description' => 'Choque hipovolémico',                                'category' => 'Síndromes'],
            ['code' => 'T78.3', 'description' => 'Edema angioneurótico',                               'category' => 'Síndromes'],
            ['code' => 'T78.4', 'description' => 'Alergia, no especificada',                           'category' => 'Síndromes'],
            ['code' => 'D69.6', 'description' => 'Trombocitopenia, no especificada',                   'category' => 'Síndromes'],
            ['code' => 'B99',   'description' => 'Enfermedad infecciosa, no especificada',             'category' => 'Síndromes'],
            ['code' => 'F05.9', 'description' => 'Delirium, no especificado',                          'category' => 'Síndromes'],
            ['code' => 'F07.2', 'description' => 'Síndrome postconmocional',                           'category' => 'Síndromes'],
            ['code' => 'G04.9', 'description' => 'Encefalitis, mielitis y encefalomielitis, no especificadas', 'category' => 'Síndromes'],
        ];

        foreach ($codes as $code) {
            Cie10Code::updateOrCreate(['code' => $code['code']], $code);
        }
    }
}
