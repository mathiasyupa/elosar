/** Public contact shared with the legal pages. */
export const CONTACT_EMAIL = 'contacto@elosar.site';

const MSP_DOCUMENTS_COPY =
  'Recetas y órdenes PDF con estructura pensada para el formato MSP; el profesional y el establecimiento completan y revisan los datos exigibles antes de emitirlas.';

export const LANDING_LINKS = [
  ['Espacios', '/#espacios'],
  ['Cómo funciona', '/#como-funciona'],
  ['Planes', '/#planes'],
  ['Preguntas', '/#preguntas'],
] as const;

export const PRACTICE_PATHS = [
  {
    icon: 'user',
    eyebrow: 'Tu espacio personal',
    title: 'Consultorio',
    description:
      'Tu práctica independiente, con agenda, expediente, atención, documentos y cobros en un espacio propio.',
    features: [
      'Agenda y atención sin cita',
      'Expediente clínico longitudinal',
      'Recetas, órdenes y certificados',
    ],
  },
  {
    icon: 'building',
    eyebrow: 'Tu espacio compartido',
    title: 'Clínica',
    description:
      'La operación de un equipo, con médicos, recepción, enfermería, caja y administración trabajando por roles.',
    features: [
      'Agenda de varios médicos',
      'Cola de atención y signos vitales',
      'Equipo con roles y permisos',
    ],
  },
] as const;

export const WORKFLOW = [
  {
    icon: 'cal',
    number: '01',
    title: 'Agenda',
    description: 'Organiza citas, disponibilidad y llegadas.',
  },
  {
    icon: 'steth',
    number: '02',
    title: 'Atención',
    description: 'Continúa con antecedentes, evolución y diagnóstico.',
  },
  {
    icon: 'file',
    number: '03',
    title: 'Documentos',
    description: 'Emite recetas, órdenes y certificados desde el mismo contexto.',
  },
  {
    icon: 'card',
    number: '04',
    title: 'Cobro',
    description: 'Cierra la atención y registra su operación financiera.',
  },
] as const;

export const ECUADOR_CONTEXT = [
  ['CIE-10', 'Búsqueda y registro estructurado de diagnósticos dentro de la consulta.'],
  ['Documentos clínicos', MSP_DOCUMENTS_COPY],
  ['RDACAA', 'Exportación en validación durante la etapa actual del producto.'],
  [
    'Facturación electrónica',
    'Genera el comprobante electrónico y el XML, los firma con el certificado .p12 configurado, los envía al SRI y conserva la autorización para el RIDE. Requiere configuración fiscal y certificado.',
  ],
] as const;

export const PLAN_PRICING = {
  personal: { monthlyUsd: 15, includedClinicalDoctors: 1 },
  clinic: { monthlyUsd: 21, includedClinicalDoctors: 2, additionalDoctorUsd: 7 },
  bundle: { monthlyUsd: 30 },
} as const;

export const PLANS = [
  {
    icon: 'user',
    title: 'Consultorio',
    eyebrow: 'Para tu práctica personal',
    price: PLAN_PRICING.personal.monthlyUsd,
    description: 'Todo lo necesario para atender y administrar tu propio consultorio.',
    features: [
      '1 médico que atiende',
      'Agenda y expediente clínico',
      'Documentos y cobros',
      '30 días de prueba',
    ],
  },
  {
    icon: 'building',
    title: 'Clínica',
    eyebrow: 'Para trabajar en equipo',
    price: PLAN_PRICING.clinic.monthlyUsd,
    description: 'Coordina la atención y la operación de tu clínica desde un solo espacio.',
    features: [
      `Incluye ${PLAN_PRICING.clinic.includedClinicalDoctors} médicos que atienden`,
      'Roles y permisos para el equipo',
      'Agenda, caja y reportes compartidos',
      `Cada médico adicional suma USD ${PLAN_PRICING.clinic.additionalDoctorUsd} al mes`,
    ],
  },
] as const;

export const FREE_ROLES = ['Recepción', 'Caja', 'Enfermería', 'Administración'] as const;

/**
 * Pantallas reales del producto. Las imágenes viven en `public/capturas/` y se
 * generan con `design/capturas/generar.mjs` contra el backend local: los
 * pacientes, las cédulas y el caso clínico son inventados. Ver el `LEEME.md` de
 * esa carpeta antes de tocarlas — son públicas y aplica la LOPDP.
 */
export const CAPTURE_SLOTS = [
  {
    title: 'Agenda',
    description: 'Citas, disponibilidad y estado de cada atención.',
    src: '/capturas/agenda.png',
    alt: 'Vista semanal de la agenda de Elosar, con las citas de lunes a viernes coloreadas según el tipo de atención.',
  },
  {
    title: 'Consulta',
    description: 'Contexto clínico y evolución del paciente.',
    src: '/capturas/consulta.png',
    alt: 'Pantalla de atención de Elosar en el paso de evaluación clínica, con la enfermedad actual, las notas del examen físico y la revisión por sistemas.',
  },
  {
    title: 'Receta',
    description: 'Prescripción preparada desde la atención.',
    src: '/capturas/receta.png',
    alt: 'Paso de tratamiento y prescripciones de Elosar, con el plan terapéutico, los signos de alarma, la fecha del próximo control y dos medicamentos prescritos.',
  },
  {
    title: 'Documento clínico',
    description: 'La receta impresa, tal como la recibe el paciente.',
    src: '/capturas/documento.png',
    alt: 'Receta de Elosar en PDF, con los datos del paciente, el diagnóstico CIE-10, los medicamentos con su cantidad y la firma del médico.',
  },
] as const;

export const TRUST_POINTS = [
  ['Espacios separados', 'Cada consultorio y clínica conserva su propio contexto de trabajo.'],
  ['Permisos por rol', 'Las funciones clínicas y administrativas se asignan según responsabilidad.'],
  ['Trazabilidad', 'Los accesos sensibles quedan registrados dentro de los flujos del sistema.'],
] as const;

export const FAQ = [
  {
    question: '¿Puedo usar consultorio y clínica con el mismo usuario?',
    answer:
      'Sí. Una misma cuenta puede tener un consultorio personal, crear o integrar una clínica y cambiar entre esos espacios sin iniciar sesión de nuevo.',
  },
  {
    question: '¿Cómo empiezo?',
    answer:
      'Creas tu cuenta, eliges el primer espacio que necesitas y comienzas una prueba de 30 días sin tarjeta.',
  },
  {
    question: '¿Cómo funciona la facturación electrónica con el SRI?',
    answer:
      'La facturación electrónica genera el comprobante electrónico y el XML, firma con el certificado .p12 configurado, envía al SRI, conserva la autorización y permite el RIDE; requiere configuración fiscal y certificado. Esta capacidad no implica aval, certificación, convenio ni afiliación con el SRI.',
  },
  {
    question: '¿Cómo apoyan las recetas y órdenes los requisitos del MSP?',
    answer: `${MSP_DOCUMENTS_COPY} Esta capacidad no implica aval, certificación, convenio ni afiliación con el MSP.`,
  },
  {
    question: '¿Las capturas contendrán datos de pacientes reales?',
    answer:
      'No. Las capturas públicas se preparan únicamente con información sintética de demostración.',
  },
] as const;
