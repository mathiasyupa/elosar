import type { Permission } from '@/api/types';
import { t } from '@/i18n/es';
import { ROUTE_ACCESS } from '@/lib/routeAccess';

export interface NavigationContext {
  isClinic: boolean;
  isDoctorAccount: boolean;
  can: (permission: Permission) => boolean;
  canAny: (permissions: Permission[]) => boolean;
}

export interface NavEntry {
  to: string;
  icon: string;
  label: string;
  perm?: Permission | Permission[];
  requiresDoctor?: boolean;
  clinicOnly?: boolean;
}

export interface NavGroup {
  label: string;
  items: NavEntry[];
}

const NAV_GROUPS: NavGroup[] = [
  {
    label: t.nav.operations,
    items: [
      { to: '/dashboard', icon: 'home', label: 'Inicio' },
      { to: '/agenda', icon: 'cal', label: t.nav.agenda, perm: ROUTE_ACCESS.agenda },
      // Nursing's daily work: the waiting room and triage. Who takes vitals sees it.
      { to: '/cola', icon: 'clock', label: 'Sala de espera', perm: ROUTE_ACCESS.triage, clinicOnly: true },
      { to: '/pacientes', icon: 'users', label: t.nav.patients, perm: ROUTE_ACCESS.patients },
      { to: '/documentos', icon: 'folder', label: t.nav.documents, perm: ROUTE_ACCESS.documents },
    ],
  },
  {
    label: t.nav.finance,
    items: [
      { to: '/facturas', icon: 'invoice', label: t.nav.invoices, perm: ROUTE_ACCESS.invoices },
      { to: '/seguros', icon: 'shield', label: t.nav.insurance, perm: ROUTE_ACCESS.insurance },
      { to: '/reportes', icon: 'chart', label: t.nav.reports, perm: ROUTE_ACCESS.reports },
    ],
  },
  {
    label: t.nav.administration,
    items: [
      { to: '/servicios', icon: 'money', label: 'Servicios', perm: ROUTE_ACCESS.services },
      { to: '/equipo', icon: 'users', label: 'Equipo', perm: ROUTE_ACCESS.team, clinicOnly: true },
      { to: '/clinicas', icon: 'building', label: t.nav.clinics },
      { to: '/perfil', icon: 'user', label: t.nav.profile, requiresDoctor: true },
      { to: '/ajustes', icon: 'cog', label: t.nav.settings, perm: ROUTE_ACCESS.settings },
    ],
  },
];

function isVisible(item: NavEntry, context: NavigationContext): boolean {
  if (item.requiresDoctor && !context.isDoctorAccount) return false;
  if (item.clinicOnly && !context.isClinic) return false;
  if (!item.perm) return true;
  return Array.isArray(item.perm) ? context.canAny(item.perm) : context.can(item.perm);
}

export function buildVisibleNavigation(context: NavigationContext): NavGroup[] {
  return NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) => isVisible(item, context)),
  })).filter((group) => group.items.length > 0);
}
