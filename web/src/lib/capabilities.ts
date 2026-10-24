import { useAuthStore } from '@/store/auth';
import type { ClinicalModule, MembershipRole, Permission, Workspace } from '@/api/types';

export interface Capabilities {
  /** The active workspace, or null before one is selected. */
  workspace: Workspace | null;
  /** Representative role in the active workspace (null for personal / unknown). */
  role: MembershipRole | null;
  /** Every role the user holds here; permissions are their union. */
  roles: MembershipRole[];
  isOwner: boolean;
  isClinic: boolean;
  isPersonal: boolean;
  /** Whether the user holds a given role in the active workspace. */
  hasRole: (role: MembershipRole) => boolean;
  /** Whether the user holds a given permission in the active workspace. */
  can: (perm: Permission) => boolean;
  /** Whether the user holds ANY of the given permissions. */
  canAny: (perms: Permission[]) => boolean;
}

/**
 * Capabilities for the ACTIVE workspace, derived from the permission set the API
 * returns per workspace (WorkspacePermissionService). The single hook the UI
 * uses to shape menus, gate pages and toggle actions. Server-side enforcement
 * (EnsurePermission middleware) is the real boundary — this is presentation.
 */
export function useWorkspaceCapabilities(): Capabilities {
  const workspaces = useAuthStore((s) => s.workspaces);
  const activeId = useAuthStore((s) => s.activeWorkspaceId);

  const workspace = workspaces.find((w) => w.id === activeId) ?? null;
  const permissions = workspace?.permissions ?? [];
  const roles = workspace?.roles ?? [];

  return {
    workspace,
    role: workspace?.membership_role ?? null,
    roles,
    isOwner: workspace?.is_owner ?? false,
    isClinic: workspace?.is_clinic ?? false,
    isPersonal: workspace ? workspace.type === 'personal' : false,
    hasRole: (role) => roles.includes(role),
    can: (perm) => permissions.includes(perm),
    canAny: (perms) => perms.some((p) => permissions.includes(p)),
  };
}

/**
 * Clinical modules are a different boundary from workspace roles. The API
 * calculates them from the authenticated doctor's approved specialties; this
 * hook only shapes the interface and never replaces server-side enforcement.
 */
export function useClinicalModules() {
  const modules = useAuthStore((state) => state.doctor?.clinical_modules ?? []);

  return {
    has: (module: ClinicalModule) => modules.includes(module),
  };
}

/** Spanish labels for membership roles (UI badges). */
export const ROLE_LABELS: Record<MembershipRole, string> = {
  doctor: 'Médico',
  nurse: 'Enfermería',
  receptionist: 'Recepción',
  billing: 'Caja',
  admin: 'Administración',
};
