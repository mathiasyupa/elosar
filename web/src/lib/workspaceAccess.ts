// Pure helpers over a workspace's commercial state. Kept free of React so the
// same rules can be asserted directly in tests and reused anywhere.

import type { Workspace, WorkspaceEntitlement } from '../api/types';

/** Published monthly prices, in USD. Unlimited clinic has a manual rate. */
export const PLAN_PRICING = {
  personal: { monthlyUsd: 15, includedClinicalDoctors: 1 },
  clinic: { monthlyUsd: 21, includedClinicalDoctors: 2, additionalDoctorUsd: 7 },
  bundle: { monthlyUsd: 30 },
} as const;

/**
 * Whether this workspace accepts writes right now.
 *
 * A workspace with no entitlement is treated as writable: it simply predates
 * the rollout and the backend has not backfilled it yet. Guessing "locked"
 * here would black out a working clinic over a serialization gap, and the API
 * is authoritative anyway — it answers 423 if it really is read-only.
 */
export function isWorkspaceWritable(workspace: Workspace | null | undefined): boolean {
  if (!workspace) return false;
  if (!workspace.entitlement) return true;

  return workspace.entitlement.can_write;
}

export function isTrial(workspace: Workspace | null | undefined): boolean {
  return workspace?.entitlement?.state === 'trial';
}

/**
 * Whole days left in the trial, counted by calendar day so "vence hoy" and
 * "queda 1 día" mean what a person expects. Null when there is no deadline.
 */
export function trialDaysLeft(
  entitlement: WorkspaceEntitlement | null | undefined,
  now: Date = new Date(),
): number | null {
  if (!entitlement?.trial_ends_at) return null;

  const end = new Date(entitlement.trial_ends_at);
  if (Number.isNaN(end.getTime())) return null;

  const startOfDay = (d: Date) => Date.UTC(d.getFullYear(), d.getMonth(), d.getDate());
  const days = Math.round((startOfDay(end) - startOfDay(now)) / 86_400_000);

  return Math.max(days, 0);
}

/** Spanish label for how much trial is left. Null when it does not apply. */
export function trialRemainingLabel(
  workspace: Workspace | null | undefined,
  now: Date = new Date(),
): string | null {
  if (!isTrial(workspace) || !isWorkspaceWritable(workspace)) return null;

  const days = trialDaysLeft(workspace?.entitlement, now);
  if (days === null) return null;

  if (days === 0) return 'Tu prueba termina hoy';
  if (days === 1) return 'Queda 1 día de prueba';

  return `Quedan ${days} días de prueba`;
}

/** What the plan costs today, given the doctors actually attending. */
export function monthlyPriceUsd(entitlement: WorkspaceEntitlement | null | undefined): number | null {
  if (!entitlement) return null;

  if (entitlement.plan_code === 'personal') return PLAN_PRICING.personal.monthlyUsd;
  if (entitlement.plan_code === 'clinic_unlimited') return null;

  return (
    PLAN_PRICING.clinic.monthlyUsd +
    entitlement.clinical_seats.additional * PLAN_PRICING.clinic.additionalDoctorUsd
  );
}

/** Plain-Spanish description of the seats a clinic uses. */
export function seatsLabel(entitlement: WorkspaceEntitlement | null | undefined): string | null {
  if (!entitlement) return null;

  if (entitlement.plan_code === 'clinic_unlimited') {
    const active = entitlement.clinical_seats.active_doctors;
    return `Médicos activos: ${active} · sin límite de cupos`;
  }

  if (entitlement.plan_code !== 'clinic') return null;

  const { included, active_doctors: active, additional } = entitlement.clinical_seats;

  if (additional === 0) {
    return `${active} de ${included} médicos incluidos`;
  }

  const extra = additional === 1 ? '1 médico adicional' : `${additional} médicos adicionales`;

  return `${active} médicos · ${extra}`;
}

/**
 * Which workspace to land on. Prefers one the user can actually work in, but
 * never discards a read-only one — losing access to a space is not the same as
 * losing the space, and its records must stay reachable.
 */
export function preferredWorkspace(workspaces: Workspace[]): Workspace | null {
  if (workspaces.length === 0) return null;

  const writable = workspaces.filter(isWorkspaceWritable);
  const pool = writable.length > 0 ? writable : workspaces;

  return pool.find((w) => w.type === 'personal') ?? pool[0] ?? null;
}
