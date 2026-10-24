export interface RouteContext {
  eyebrow: string;
  title: string;
}

interface RouteContextMatch {
  pattern: RegExp;
  context: RouteContext;
}

const ROUTE_CONTEXTS: RouteContextMatch[] = [
  {
    pattern: /^\/pacientes\/[^/]+\/atencion\/?$/,
    context: { eyebrow: 'Jornada clínica', title: 'Atención' },
  },
  {
    // La ruta vieja solo redirige, pero el rótulo evita un parpadeo con el
    // título de reserva mientras React Router resuelve la redirección.
    pattern: /^\/pacientes\/[^/]+\/consulta\/?$/,
    context: { eyebrow: 'Jornada clínica', title: 'Atención' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/resumen\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Resumen' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/historial\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Historia clínica' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/recetas\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Recetas' },
  },
  {
    // "Exámenes" everywhere: the side rail already said that while the header
    // said "Órdenes" and the table said something else again. Three names for
    // one screen is how it stops being findable. The URL keeps /ordenes so
    // existing links do not break.
    pattern: /^\/pacientes\/[^/]+\/ordenes\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Exámenes' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/vacunas\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Vacunas' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/certificados\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Certificados' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/documentos\/?$/,
    context: { eyebrow: 'Pacientes / Expediente', title: 'Documentos' },
  },
  {
    pattern: /^\/pacientes\/[^/]+\/?$/,
    context: { eyebrow: 'Pacientes', title: 'Expediente clínico' },
  },
  {
    pattern: /^\/facturas\/[^/]+\/?$/,
    context: { eyebrow: 'Finanzas / Facturación', title: 'Detalle de factura' },
  },
  {
    pattern: /^\/triaje\/[^/]+\/?$/,
    context: { eyebrow: 'Operaciones / Cola clínica', title: 'Signos vitales' },
  },
  {
    pattern: /^\/dashboard\/?$/,
    context: { eyebrow: 'Jornada clínica', title: 'Inicio' },
  },
  {
    pattern: /^\/agenda\/?$/,
    context: { eyebrow: 'Operaciones', title: 'Agenda' },
  },
  {
    pattern: /^\/cola\/?$/,
    context: { eyebrow: 'Operaciones', title: 'Cola clínica' },
  },
  {
    pattern: /^\/pacientes\/?$/,
    context: { eyebrow: 'Operaciones', title: 'Pacientes' },
  },
  {
    pattern: /^\/documentos\/?$/,
    context: { eyebrow: 'Operaciones', title: 'Documentos' },
  },
  {
    pattern: /^\/facturas\/?$/,
    context: { eyebrow: 'Finanzas', title: 'Facturación' },
  },
  {
    pattern: /^\/seguros\/?$/,
    context: { eyebrow: 'Finanzas', title: 'Seguros' },
  },
  {
    pattern: /^\/reportes\/?$/,
    context: { eyebrow: 'Finanzas', title: 'Reportes' },
  },
  {
    pattern: /^\/servicios\/?$/,
    context: { eyebrow: 'Administración', title: 'Servicios' },
  },
  {
    pattern: /^\/equipo\/?$/,
    context: { eyebrow: 'Administración', title: 'Equipo' },
  },
  {
    pattern: /^\/clinicas\/?$/,
    context: { eyebrow: 'Administración', title: 'Clínicas' },
  },
  {
    pattern: /^\/perfil\/?$/,
    context: { eyebrow: 'Cuenta', title: 'Perfil profesional' },
  },
  {
    pattern: /^\/ajustes\/?$/,
    context: { eyebrow: 'Cuenta', title: 'Ajustes' },
  },
];

const FALLBACK_CONTEXT: RouteContext = {
  eyebrow: 'Elosar',
  title: 'Área de trabajo',
};

export function getRouteContext(pathname: string): RouteContext {
  return ROUTE_CONTEXTS.find(({ pattern }) => pattern.test(pathname))?.context ?? FALLBACK_CONTEXT;
}

/**
 * Rutas que se pintan sin el armazón de la app.
 *
 * Atender es lo único que hoy justifica esconder la navegación: el paciente
 * está delante y mezclar contextos cuesta atención clínica. El triaje de
 * enfermería **conserva** el armazón — es una decisión de producto tomada
 * a propósito, no un caso olvidado.
 */
const FOCUS_ROUTES = [
  // The patient file is opened in its own named tab. Its local clinical rail
  // replaces the global navigation, so the doctor gets the same working width
  // as in an encounter without losing the patient context.
  /^\/pacientes\/[^/]+(?:\/[^/]+)?\/?$/,
];

export function isFocusRoute(pathname: string): boolean {
  return FOCUS_ROUTES.some((pattern) => pattern.test(pathname));
}
