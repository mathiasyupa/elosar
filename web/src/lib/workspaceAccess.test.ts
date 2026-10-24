import { describe, expect, it } from 'vitest';

import type { Workspace, WorkspaceEntitlement } from '../api/types';
import {
  isWorkspaceWritable,
  monthlyPriceUsd,
  preferredWorkspace,
  seatsLabel,
  trialDaysLeft,
  trialRemainingLabel,
} from './workspaceAccess';

function entitlement(overrides: Partial<WorkspaceEntitlement> = {}): WorkspaceEntitlement {
  return {
    state: 'trial',
    effective_access: 'full',
    can_write: true,
    plan_code: 'personal',
    trial_ends_at: '2026-09-02T23:59:59-05:00',
    access_ends_at: null,
    clinical_seats: { included: 1, active_doctors: 0, additional: 0 },
    bundle_eligible: false,
    is_legacy_review: false,
    ...overrides,
  };
}

function workspace(overrides: Partial<Workspace> = {}): Workspace {
  return {
    id: 1,
    name: 'Consultorio',
    type: 'personal',
    is_clinic: false,
    entitlement: entitlement(),
    logo_path: null,
    sri_enabled: false,
    membership_role: 'doctor',
    roles: ['doctor'],
    is_owner: true,
    permissions: [],
    ...overrides,
  };
}

describe('isWorkspaceWritable', () => {
  it('follows can_write rather than the stored state', () => {
    // A lapsed trial is still stored as `trial` until the nightly job runs.
    const lapsed = workspace({
      entitlement: entitlement({ state: 'trial', effective_access: 'read_only', can_write: false }),
    });

    expect(isWorkspaceWritable(lapsed)).toBe(false);
  });

  it('treats a workspace the backfill has not reached as writable', () => {
    // Guessing "locked" would black out a working clinic over a missing field.
    expect(isWorkspaceWritable(workspace({ entitlement: null }))).toBe(true);
  });

  it('is false when there is no workspace at all', () => {
    expect(isWorkspaceWritable(null)).toBe(false);
  });
});

describe('trial countdown', () => {
  const ends = entitlement({ trial_ends_at: '2026-09-02T23:59:59-05:00' });

  it('counts whole calendar days and never goes negative', () => {
    expect(trialDaysLeft(ends, new Date('2026-08-30T10:00:00-05:00'))).toBe(3);
    expect(trialDaysLeft(ends, new Date('2026-09-02T08:00:00-05:00'))).toBe(0);
    expect(trialDaysLeft(ends, new Date('2026-09-20T08:00:00-05:00'))).toBe(0);
  });

  it('speaks Spanish about what is left', () => {
    const ws = workspace({ entitlement: ends });

    expect(trialRemainingLabel(ws, new Date('2026-08-28T10:00:00-05:00'))).toBe('Quedan 5 días de prueba');
    expect(trialRemainingLabel(ws, new Date('2026-09-01T10:00:00-05:00'))).toBe('Queda 1 día de prueba');
    expect(trialRemainingLabel(ws, new Date('2026-09-02T10:00:00-05:00'))).toBe('Tu prueba termina hoy');
  });

  it('says nothing once the trial is over or the plan is active', () => {
    const lapsed = workspace({
      entitlement: entitlement({ effective_access: 'read_only', can_write: false }),
    });
    const active = workspace({ entitlement: entitlement({ state: 'active', trial_ends_at: null }) });

    expect(trialRemainingLabel(lapsed)).toBeNull();
    expect(trialRemainingLabel(active)).toBeNull();
  });
});

describe('pricing', () => {
  it('prices a consulting room at USD 15', () => {
    expect(monthlyPriceUsd(entitlement({ plan_code: 'personal' }))).toBe(15);
  });

  it('prices a clinic at USD 21 with its two included doctors', () => {
    const clinic = entitlement({
      plan_code: 'clinic',
      clinical_seats: { included: 2, active_doctors: 2, additional: 0 },
    });

    expect(monthlyPriceUsd(clinic)).toBe(21);
  });

  it('adds USD 7 for each doctor beyond the two included', () => {
    const clinic = entitlement({
      plan_code: 'clinic',
      clinical_seats: { included: 2, active_doctors: 5, additional: 3 },
    });

    expect(monthlyPriceUsd(clinic)).toBe(42);
  });

  it('does not present the internal zero MRR value as an unlimited clinic price', () => {
    const unlimited = entitlement({
      plan_code: 'clinic_unlimited',
      clinical_seats: { included: null, active_doctors: 24, additional: 0 },
    });

    expect(monthlyPriceUsd(unlimited)).toBeNull();
    expect(seatsLabel(unlimited)).toBe('Médicos activos: 24 · sin límite de cupos');
  });

  it('describes seats without inventing extras', () => {
    expect(
      seatsLabel(
        entitlement({ plan_code: 'clinic', clinical_seats: { included: 2, active_doctors: 1, additional: 0 } }),
      ),
    ).toBe('1 de 2 médicos incluidos');

    expect(
      seatsLabel(
        entitlement({ plan_code: 'clinic', clinical_seats: { included: 2, active_doctors: 3, additional: 1 } }),
      ),
    ).toBe('3 médicos · 1 médico adicional');

    // A consulting room has no seat conversation.
    expect(seatsLabel(entitlement({ plan_code: 'personal' }))).toBeNull();
  });
});

describe('preferredWorkspace', () => {
  const readOnly = entitlement({ effective_access: 'read_only', can_write: false });

  it('prefers a workspace the user can actually work in', () => {
    const personal = workspace({ id: 1, type: 'personal', entitlement: readOnly });
    const clinic = workspace({ id: 2, type: 'clinic', is_clinic: true });

    expect(preferredWorkspace([personal, clinic])?.id).toBe(2);
  });

  it('still returns a read-only workspace when nothing else is available', () => {
    const personal = workspace({ id: 1, entitlement: readOnly });

    // Its records must stay reachable — losing write access is not losing the space.
    expect(preferredWorkspace([personal])?.id).toBe(1);
  });

  it('returns null with no workspaces', () => {
    expect(preferredWorkspace([])).toBeNull();
  });
});
