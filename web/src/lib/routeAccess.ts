import type { Permission } from '@/api/types';

/**
 * Who may open each section. The single source for BOTH the route guards in
 * App.tsx and the sidebar in navigation.ts, so a menu entry and the page it
 * opens can never disagree. With several permissions, ANY of them grants it.
 *
 * Presentation only: the API enforces the same rules (EnsurePermission). This
 * keeps someone who types a URL from landing on a form they cannot use, or on
 * a page that waits forever on a 403.
 */
export const ROUTE_ACCESS = {
  agenda: 'appointments.view',
  patients: 'patients.view',
  documents: 'records.view',
  invoices: 'billing.view',
  insurance: 'billing.view',
  reports: 'reports.view',
  services: 'appointments.manage',
  team: 'team.view',
  settings: 'settings.view',
  queue: 'appointments.view',
  triage: 'vitals.create',
} as const satisfies Record<string, Permission | Permission[]>;

export type RouteKey = keyof typeof ROUTE_ACCESS;

/**
 * Inside a patient's expediente: the sections that show clinical content
 * need records.view; the atención itself needs records.manage. Resumen and
 * Vacunas stay open to whoever can see the patient (nursing, reception).
 */
export const PATIENT_SECTION_ACCESS = {
  historial: 'records.view',
  recetas: 'records.view',
  ordenes: 'records.view',
  gineco: 'records.view',
  certificados: 'records.view',
  documentos: 'records.view',
  atencion: 'records.manage',
} as const satisfies Record<string, Permission>;
