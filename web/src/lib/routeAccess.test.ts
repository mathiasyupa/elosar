import { describe, expect, it } from 'vitest';
import type { Permission } from '@/api/types';
import { buildVisibleNavigation } from '@/components/shell/navigation';
import { PATIENT_SECTION_ACCESS, ROUTE_ACCESS, type RouteKey } from './routeAccess';

/**
 * The permission sets each clinic role really has — copied from
 * WorkspacePermissionService::ROLE_PERMISSIONS in medico-backend. If the
 * backend changes a role, change it here too: these tests pin what each
 * person sees.
 */
const ROLES: Record<string, Permission[]> = {
  doctor: [
    'dashboard.view', 'appointments.view', 'appointments.manage', 'appointments.checkin',
    'patients.view', 'patients.manage', 'records.view', 'records.manage', 'vitals.create',
    'assistant.use', 'team.view', 'billing.view', 'reports.view', 'reports.clinical', 'settings.view',
  ],
  nurse: ['dashboard.view', 'appointments.view', 'appointments.checkin', 'patients.view', 'patients.manage', 'vitals.create', 'team.view', 'settings.view'],
  receptionist: [
    'dashboard.view', 'appointments.view', 'appointments.manage', 'appointments.checkin',
    'patients.view', 'patients.manage', 'billing.view', 'billing.manage', 'team.view', 'settings.view',
  ],
  billing: ['dashboard.view', 'billing.view', 'billing.manage', 'reports.view', 'settings.view'],
  admin: [
    'dashboard.view', 'appointments.view', 'appointments.manage', 'appointments.checkin',
    'patients.view', 'patients.manage', 'team.view', 'billing.view', 'billing.manage',
    'reports.view', 'finance.view', 'finance.manage', 'settings.view',
  ],
};

const can = (role: string) => (p: Permission) => ROLES[role].includes(p);

function sections(role: string): RouteKey[] {
  return (Object.keys(ROUTE_ACCESS) as RouteKey[]).filter((key) => {
    const perm = ROUTE_ACCESS[key] as Permission | Permission[];
    return (Array.isArray(perm) ? perm : [perm]).some(can(role));
  });
}

function menu(role: string): string[] {
  return buildVisibleNavigation({
    isClinic: true,
    isDoctorAccount: role === 'doctor',
    can: can(role),
    canAny: (perms) => perms.some(can(role)),
  }).flatMap((g) => g.items.map((i) => i.label));
}

describe('Cada rol entra solo a lo suyo', () => {
  it('enfermería: agenda, pacientes, sala de espera y triaje — nada de dinero ni historias', () => {
    expect(sections('nurse')).toEqual(['agenda', 'patients', 'team', 'settings', 'queue', 'triage']);
    expect(menu('nurse')).toEqual(['Inicio', 'Agenda', 'Sala de espera', 'Pacientes', 'Equipo', 'Clínicas', 'Ajustes']);
  });

  it('recepción: agenda, pacientes, cobros y servicios — sin historias ni triaje', () => {
    expect(sections('receptionist')).toEqual(['agenda', 'patients', 'invoices', 'insurance', 'services', 'team', 'settings', 'queue']);
    expect(menu('receptionist')).not.toContain('Sala de espera');
    expect(menu('receptionist')).not.toContain('Documentos');
  });

  it('caja: solo dinero', () => {
    expect(sections('billing')).toEqual(['invoices', 'insurance', 'reports', 'settings']);
    expect(menu('billing')).toEqual(['Inicio', 'Facturación', 'Seguros', 'Reportes', 'Clínicas', 'Ajustes']);
  });

  it('administración: lo administrativo, nada clínico', () => {
    expect(sections('admin')).not.toContain('documents');
    expect(sections('admin')).not.toContain('triage');
    expect(menu('admin')).not.toContain('Documentos');
  });

  it('el médico ve todo lo clínico, incluida la sala de espera', () => {
    expect(sections('doctor')).toEqual(expect.arrayContaining(['documents', 'queue', 'triage', 'settings']));
    expect(menu('doctor')).toContain('Sala de espera');
  });

  it('dentro de la ficha, solo el médico abre la atención y las secciones clínicas', () => {
    for (const role of ['nurse', 'receptionist', 'billing', 'admin']) {
      const open = Object.entries(PATIENT_SECTION_ACCESS).filter(([, p]) => can(role)(p)).map(([k]) => k);
      expect(open, role).toEqual([]);
    }
    expect(Object.values(PATIENT_SECTION_ACCESS).every(can('doctor'))).toBe(true);
  });
});
