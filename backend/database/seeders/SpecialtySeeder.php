<?php

namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

class SpecialtySeeder extends Seeder
{
    /**
     * Legacy English names (first i18n pass, pre-Spanish content convention)
     * renamed in place — same `id`, so existing Doctor.specialty_id / the
     * doctor_specialties pivot keep pointing at the right row.
     */
    private const RENAMES = [
        'Cardiology' => 'Cardiología',
        'Pediatrics' => 'Pediatría',
        'Gynecology and Obstetrics' => 'Ginecología y Obstetricia',
        'Internal Medicine' => 'Medicina Interna',
        'General Surgery' => 'Cirugía General',
        'Dermatology' => 'Dermatología',
        'Traumatology' => 'Traumatología',
        'Ophthalmology' => 'Oftalmología',
        'Psychiatry' => 'Psiquiatría',
        'Endocrinology' => 'Endocrinología',
    ];

    /**
     * Broader catalog of medical specialties (name => description). Content
     * in Spanish per the language convention (clinical/market-facing data).
     * Doctors can still add a specialty that's missing via the "create if
     * not found" flow on self-registration — this is just a solid baseline.
     */
    private const SPECIALTIES = [
        'Medicina General' => 'Atención primaria y consulta médica general.',
        'Medicina Familiar' => 'Atención integral del individuo y la familia en todas las etapas de vida.',
        'Cardiología' => 'Diagnóstico y tratamiento de las enfermedades del corazón y del aparato circulatorio.',
        'Pediatría' => 'Estudia al niño y sus enfermedades en la edad pediátrica.',
        'Ginecología y Obstetricia' => 'Cuidado del sistema reproductor femenino, el embarazo, el parto y el puerperio.',
        'Medicina Interna' => 'Atención médica integrada y completa de los problemas de salud en pacientes adultos.',
        'Cirugía General' => 'Intervenciones quirúrgicas del aparato digestivo, sistema endocrino y partes blandas.',
        'Dermatología' => 'Estudio, diagnóstico y tratamiento de las afecciones de la piel, cabello y uñas.',
        'Traumatología' => 'Estudio y tratamiento de las lesiones del aparato locomotor.',
        'Ortopedia' => 'Diagnóstico y tratamiento de alteraciones del aparato locomotor.',
        'Cirugía Vascular' => 'Tratamiento quirúrgico de enfermedades de los vasos sanguíneos.',
        'Medicina Fetal y Reproducción Asistida' => 'Atención especializada en medicina fetal y reproducción asistida.',
        'Oftalmología' => 'Patologías y tratamientos del globo ocular, párpados y sistema lagrimal.',
        'Psiquiatría' => 'Estudio y tratamiento de los trastornos mentales.',
        'Endocrinología' => 'Diagnóstico y tratamiento de los trastornos del sistema endocrino y el metabolismo.',
        'Neurología' => 'Diagnóstico y tratamiento de las enfermedades del sistema nervioso.',
        'Otorrinolaringología' => 'Enfermedades del oído, nariz y garganta.',
        'Urología' => 'Enfermedades del sistema urinario y del aparato reproductor masculino.',
        'Nefrología' => 'Diagnóstico y tratamiento de las enfermedades del riñón.',
        'Neumología' => 'Diagnóstico y tratamiento de las enfermedades del sistema respiratorio.',
        'Gastroenterología' => 'Diagnóstico y tratamiento de las enfermedades del sistema digestivo.',
        'Reumatología' => 'Enfermedades autoinmunes y del sistema musculoesquelético (artritis, lupus, etc).',
        'Oncología' => 'Diagnóstico y tratamiento del cáncer.',
        'Geriatría' => 'Atención médica integral del adulto mayor.',
        'Anestesiología' => 'Manejo del dolor y la anestesia en procedimientos quirúrgicos.',
        'Medicina Física y Rehabilitación' => 'Recuperación funcional tras una lesión o enfermedad.',
        'Nutrición y Dietética' => 'Evaluación y tratamiento nutricional.',
        'Psicología Clínica' => 'Evaluación y tratamiento psicológico.',
        'Alergología e Inmunología' => 'Diagnóstico y tratamiento de alergias y enfermedades del sistema inmune.',
        'Infectología' => 'Diagnóstico y tratamiento de las enfermedades infecciosas.',
        'Medicina de Emergencias' => 'Atención médica urgente y estabilización del paciente crítico.',
        'Hematología' => 'Diagnóstico y tratamiento de las enfermedades de la sangre.',
        'Odontología General' => 'Salud bucal y dental general.',
    ];

    public function run(): void
    {
        foreach (self::RENAMES as $english => $spanish) {
            Specialty::where('name', $english)->update(['name' => $spanish]);
        }

        foreach (self::SPECIALTIES as $name => $description) {
            Specialty::updateOrCreate(
                ['name' => $name], // Evita duplicados en ejecuciones sucesivas
                ['description' => $description]
            );
        }

        // The public endpoint may create more specialty names, but capability
        // grants remain server-owned. Keep the approved canonical row enabled
        // after a fresh seed and after a catalog refresh.
        Specialty::query()
            ->where('name', 'Ginecología y Obstetricia')
            ->update([
                'capabilities' => json_encode([Specialty::CAPABILITY_GYNECO_OBSTETRICS], JSON_THROW_ON_ERROR),
            ]);
    }
}
