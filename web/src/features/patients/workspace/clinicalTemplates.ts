import type { ConsultationType } from '@/api/types';

export type StructuredExamField = {
  key: string;
  label: string;
  kind?: 'text' | 'date' | 'textarea' | 'boolean';
  placeholder?: string;
  /** Abre un bloque con este título. Solo lo llevan las plantillas compuestas:
   *  con 17 campos seguidos y sin agrupar, no se distingue dónde acaba el examen
   *  general y empieza lo específico. */
  section?: string;
};

export const CURRENT_CLINICAL_TEMPLATE_VERSION = 1;

/** El examen físico de cabeza a pies. Suelto porque **se compone**: las plantillas
 *  de especialidad lo llevan delante en vez de sustituirlo. */
const GENERAL_SEGMENTS: readonly StructuredExamField[] = [
  { key: 'cabeza', label: 'Cabeza', kind: 'textarea', section: 'Exploración física por segmentos' },
  { key: 'cuello', label: 'Cuello', kind: 'textarea' },
  { key: 'torax', label: 'Tórax', kind: 'textarea' },
  { key: 'abdomen', label: 'Abdomen', kind: 'textarea' },
  { key: 'miembros', label: 'Miembros superiores e inferiores', kind: 'textarea' },
  { key: 'genito_urinario', label: 'Génito-urinario', kind: 'textarea' },
];

/**
 * Frozen snapshots of the clinical exploration shown to a doctor.
 *
 * A later revision adds another version; it never mutates this v1 snapshot.
 * The backend remains the authority that assigns the version to a record.
 */
export const CLINICAL_TEMPLATES: Record<number, Record<ConsultationType, readonly StructuredExamField[]>> = {
  1: {
    general: [
      { key: 'cabeza', label: 'Cabeza', kind: 'textarea' },
      { key: 'cuello', label: 'Cuello', kind: 'textarea' },
      { key: 'torax', label: 'Tórax', kind: 'textarea' },
      { key: 'abdomen', label: 'Abdomen', kind: 'textarea' },
      { key: 'miembros', label: 'Miembros superiores e inferiores', kind: 'textarea' },
      { key: 'genito_urinario', label: 'Génito-urinario', kind: 'textarea' },
    ],
    gynecological: [
      { key: 'menarca', label: 'Menarquia (edad)' },
      { key: 'fum', label: 'FUM (última menstruación)', kind: 'date' },
      { key: 'ciclo', label: 'Ciclo menstrual' },
      { key: 'gestas', label: 'Gestas' },
      { key: 'partos', label: 'Partos' },
      { key: 'cesareas', label: 'Cesáreas' },
      { key: 'abortos', label: 'Abortos' },
      { key: 'metodo_anticonceptivo', label: 'Método anticonceptivo' },
      { key: 'papanicolaou', label: 'Último Papanicolaou' },
      { key: 'examen_mamario', label: 'Examen mamario', kind: 'textarea' },
      { key: 'examen_pelvico', label: 'Examen pélvico', kind: 'textarea' },
    ],
    pediatric: [
      { key: 'motor_grueso', label: 'Motor grueso' },
      { key: 'motor_fino', label: 'Motor fino' },
      { key: 'lenguaje', label: 'Lenguaje' },
      { key: 'social', label: 'Social / adaptativo' },
      // Ingreso manual v1; el cálculo OMS versionado pertenece a F2.
      { key: 'percentil_peso', label: 'Percentil peso' },
      { key: 'percentil_talla', label: 'Percentil talla' },
      { key: 'perimetro_cefalico', label: 'Perímetro cefálico (cm)' },
      { key: 'desarrollo_observaciones', label: 'Observaciones del desarrollo', kind: 'textarea' },
    ],
    prenatal: [
      { key: 'edad_gestacional', label: 'Edad gestacional (semanas)' },
      { key: 'altura_uterina', label: 'Altura uterina (cm)' },
      { key: 'fcf', label: 'FCF (lpm)' },
      { key: 'presentacion', label: 'Presentación' },
      { key: 'movimientos_fetales', label: 'Movimientos fetales' },
      { key: 'edemas', label: 'Edemas' },
      { key: 'observaciones_prenatal', label: 'Observaciones', kind: 'textarea' },
    ],
    preventive: [
      { key: 'agudeza_visual', label: 'Agudeza visual' },
      { key: 'agudeza_auditiva', label: 'Agudeza auditiva' },
      { key: 'salud_bucal', label: 'Salud bucal' },
      { key: 'estado_nutricional', label: 'Estado nutricional' },
      { key: 'riesgo_cardiovascular', label: 'Riesgo cardiovascular' },
      { key: 'recomendaciones_preventivas', label: 'Recomendaciones preventivas', kind: 'textarea' },
    ],
  },
  2: {
    general: [], preventive: [],
    pediatric: [
      { key: 'motor_grueso', label: 'Motor grueso' },
      { key: 'motor_fino', label: 'Motor fino' },
      { key: 'lenguaje', label: 'Lenguaje' },
      { key: 'social', label: 'Social / adaptativo' },
      { key: 'desarrollo_observaciones', label: 'Observaciones del desarrollo', kind: 'textarea' },
    ],
    // Desde v2 los segmentos corporales son PARTE de la plantilla en vez de estar
    // sustituidos por ella: elegir «Ginecológica» o «Control prenatal» hacía
    // desaparecer cabeza, cuello, tórax y abdomen del formulario, así que la
    // consulta más frecuente en gineco-obstetricia era la única que perdía el
    // examen físico. Una embarazada también tiene tórax.
    //
    // Espejo exacto de `ClinicalTemplateRegistry` v2 en el backend: si las dos
    // listas se separan, el servidor rechaza la exploración con «incluye un campo
    // que no pertenece a esta plantilla».
    gynecological: [
      ...GENERAL_SEGMENTS,
      { key: 'menarca', label: 'Menarquia (edad)', section: 'Ginecológico' },
      { key: 'fum', label: 'FUM (última menstruación)', kind: 'date' },
      { key: 'ciclo', label: 'Ciclo menstrual' },
      { key: 'gestas', label: 'Gestas' },
      { key: 'partos', label: 'Partos' },
      { key: 'cesareas', label: 'Cesáreas' },
      { key: 'abortos', label: 'Abortos' },
      { key: 'metodo_anticonceptivo', label: 'Método anticonceptivo' },
      { key: 'papanicolaou', label: 'Último Papanicolaou' },
      { key: 'examen_mamario', label: 'Examen mamario', kind: 'textarea' },
      { key: 'examen_pelvico', label: 'Examen pélvico', kind: 'textarea' },
    ],
    prenatal: [
      ...GENERAL_SEGMENTS,
      // `edad_gestacional` sigue siendo manual: es lo que necesita una consulta
      // sin embarazo registrado. Cuando SÍ lo hay, la edad la calcula el servidor
      // y se ve en la banda de la cabecera — ver ADR-009.
      { key: 'edad_gestacional', label: 'Edad gestacional (semanas)', section: 'Control prenatal' },
      { key: 'altura_uterina', label: 'Altura uterina (cm)' },
      { key: 'fcf', label: 'FCF (lpm)' },
      { key: 'presentacion', label: 'Presentación' },
      { key: 'movimientos_fetales', label: 'Movimientos fetales' },
      { key: 'edemas', label: 'Edemas' },
      { key: 'observaciones_prenatal', label: 'Observaciones', kind: 'textarea' },
    ],
  },
  3: {
    general: [], gynecological: [], pediatric: [], preventive: [],
    // Espejo exacto de `ClinicalTemplateRegistry` v3. Cuatro tamizajes de
    // sí/no que antes solo cabían en "Observaciones": son registro operativo
    // (¿se preguntó, se habló?), no un puntaje clínico, así que se repiten por
    // control en vez de fijarse "una casilla por trimestre" al estilo del
    // competidor revisado — la GPC del MSP no respalda esa estructura fija,
    // solo cita violencia intrafamiliar en la anamnesis del primer control.
    prenatal: [
      ...GENERAL_SEGMENTS,
      { key: 'edad_gestacional', label: 'Edad gestacional (semanas)', section: 'Control prenatal' },
      { key: 'altura_uterina', label: 'Altura uterina (cm)' },
      { key: 'fcf', label: 'FCF (lpm)' },
      { key: 'presentacion', label: 'Presentación' },
      { key: 'movimientos_fetales', label: 'Movimientos fetales' },
      { key: 'edemas', label: 'Edemas' },
      {
        key: 'tamizaje_violencia_intrafamiliar',
        label: 'Tamizaje de violencia intrafamiliar',
        kind: 'boolean',
        section: 'Tamizajes y consejería de este control',
      },
      { key: 'tamizaje_tabaco_alcohol_drogas', label: 'Tamizaje de tabaco, alcohol o drogas', kind: 'boolean' },
      { key: 'plan_parto_conversado', label: 'Plan de parto y emergencia conversado', kind: 'boolean' },
      { key: 'consejeria_lactancia', label: 'Consejería de lactancia materna', kind: 'boolean' },
      { key: 'observaciones_prenatal', label: 'Observaciones', kind: 'textarea' },
    ],
  },
};

export const CONSULTATION_TYPE_LABELS: Record<ConsultationType, string> = {
  general: 'General',
  gynecological: 'Ginecológica',
  pediatric: 'Pediátrica / desarrollo',
  prenatal: 'Control prenatal',
  preventive: 'Control preventivo',
};

/** Debe coincidir con `ClinicalTemplateRegistry::currentVersion()` del backend:
 *  el servidor sella la versión y valida contra ella, así que datar aquí una
 *  distinta se paga con un 422 en mitad de la consulta. */
export function currentTemplateVersionFor(type: ConsultationType): number {
  if (type === 'prenatal') return 3;
  return type === 'pediatric' || type === 'gynecological' ? 2 : CURRENT_CLINICAL_TEMPLATE_VERSION;
}

export function fieldsForTemplate(type: ConsultationType, version = currentTemplateVersionFor(type)) {
  return CLINICAL_TEMPLATES[version]?.[type] ?? [];
}

/** Never suppress clinical text: unknown versions and keys use their raw key. */
export function examLabelForRecord(type: string | null | undefined, version: number | null | undefined, key: string) {
  if (!type || !(type in CONSULTATION_TYPE_LABELS) || version == null) return key;
  const fields = CLINICAL_TEMPLATES[version]?.[type as ConsultationType];
  return fields?.find((field) => field.key === key)?.label ?? key;
}
