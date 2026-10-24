// Doctor-facing UI strings (Spanish, Ecuador market). The backend already
// returns localized `status_label` fields; this module covers the static chrome
// and shared formatting helpers.

export const t = {
  appName: 'Elosar',
  appTagline: 'Práctica médica',
  nav: {
    operations: 'Operación',
    finance: 'Finanzas',
    administration: 'Administración',
    dashboard: 'Dashboard',
    agenda: 'Agenda',
    patients: 'Pacientes',
    documents: 'Documentos',
    clinics: 'Clínicas',
    invoices: 'Facturación',
    insurance: 'Seguros',
    reports: 'Reportes',
    profile: 'Perfil Profesional',
    settings: 'Ajustes',
    logout: 'Cerrar sesión',
    newConsultation: 'Nueva consulta',
    search: 'Buscar paciente, factura, cédula…',
  },
  login: {
    title: 'Iniciar sesión',
    subtitle: 'Accede con tu cuenta de médico',
    email: 'Correo electrónico',
    password: 'Contraseña',
    submit: 'Entrar',
    submitting: 'Entrando…',
    invalid: 'Credenciales no válidas.',
  },
  agenda: {
    title: 'Agenda',
    date: 'Fecha',
    empty: 'No hay citas para esta fecha.',
  },
  patients: {
    title: 'Pacientes',
    search: 'Buscar por nombre o cédula…',
    empty: 'No se encontraron pacientes.',
    history: 'Historia clínica',
    noHistory: 'Sin consultas registradas.',
  },
  invoices: {
    title: 'Facturas',
    empty: 'No hay facturas.',
    number: 'Número',
    date: 'Fecha',
    patient: 'Paciente',
    total: 'Total',
    due: 'Saldo',
    status: 'Estado',
  },
  common: {
    loading: 'Cargando…',
    error: 'Ocurrió un error. Intenta de nuevo.',
    back: 'Volver',
    workspace: 'Espacio de trabajo',
  },
} as const;

/** Formats an ISO date/datetime string as a localized Ecuador date. */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  // A date-only string ("2026-06-10") is parsed as UTC midnight by the spec,
  // which renders as the PREVIOUS day in Ecuador (UTC-5). Calendar dates carry
  // no timezone, so build them in local time; datetimes keep default parsing.
  const date = /^\d{4}-\d{2}-\d{2}$/.test(value)
    ? new Date(`${value}T00:00:00`)
    : new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleDateString('es-EC', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

/** Formats an ISO datetime string as a localized time (HH:mm). */
export function formatTime(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' });
}

/** Formats a decimal-string money amount as USD (Ecuador's currency). */
export function formatMoney(value: string | null | undefined): string {
  if (value == null) return '—';
  const amount = Number(value);
  return Number.isNaN(amount)
    ? `$${value}`
    : amount.toLocaleString('es-EC', { style: 'currency', currency: 'USD' });
}
