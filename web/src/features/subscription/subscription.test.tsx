// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { Workspace, WorkspaceEntitlement } from '@/api/types';
import { WorkspaceAccessBanner } from './WorkspaceAccessBanner';
import { WorkspacePlanCard } from './WorkspacePlanCard';

function entitlement(overrides: Partial<WorkspaceEntitlement> = {}): WorkspaceEntitlement {
  return {
    state: 'active',
    effective_access: 'full',
    can_write: true,
    plan_code: 'personal',
    trial_ends_at: null,
    access_ends_at: null,
    clinical_seats: { included: 1, active_doctors: 1, additional: 0 },
    bundle_eligible: false,
    is_legacy_review: false,
    ...overrides,
  };
}

function workspace(entitlementOverrides: Partial<WorkspaceEntitlement> = {}): Workspace {
  return {
    id: 1,
    name: 'Consultorio',
    type: 'personal',
    is_clinic: false,
    entitlement: entitlement(entitlementOverrides),
    logo_path: null,
    sri_enabled: false,
    membership_role: 'doctor',
    roles: ['doctor'],
    is_owner: true,
    permissions: [],
  };
}

afterEach(() => {
  cleanup();
  window.localStorage.clear();
  vi.useRealTimers();
});

describe('WorkspaceAccessBanner', () => {
  it('says nothing when the plan is healthy', () => {
    const { container } = render(<WorkspaceAccessBanner workspace={workspace()} />);

    // A permanent banner about a working account is noise.
    expect(container.innerHTML).toBe('');
  });

  it('counts down a running trial', () => {
    const ends = new Date(Date.now() + 5 * 86_400_000).toISOString();

    render(<WorkspaceAccessBanner workspace={workspace({ state: 'trial', trial_ends_at: ends })} />);

    expect(screen.getByText(/días de prueba/i)).toBeTruthy();
  });

  it('keeps a closed trial notice hidden until the last 30 days', () => {
    const now = new Date('2026-10-14T12:00:00Z');
    vi.useFakeTimers();
    vi.setSystemTime(now);
    const ends = new Date(now.getTime() + 50 * 86_400_000).toISOString();
    const trialWorkspace = workspace({ state: 'trial', trial_ends_at: ends });
    const { unmount } = render(<WorkspaceAccessBanner workspace={trialWorkspace} />);

    fireEvent.click(screen.getByRole('button', { name: 'Cerrar aviso de prueba' }));
    expect(screen.queryByText(/días de prueba/i)).toBeNull();

    unmount();
    const { rerender } = render(<WorkspaceAccessBanner workspace={trialWorkspace} />);
    expect(screen.queryByText(/días de prueba/i)).toBeNull();

    vi.setSystemTime(new Date(now.getTime() + 25 * 86_400_000));
    rerender(<WorkspaceAccessBanner workspace={trialWorkspace} />);
    expect(screen.getByText(/días de prueba/i)).toBeTruthy();
  });

  it('explains read-only without threatening the doctor with data loss', () => {
    render(
      <WorkspaceAccessBanner
        workspace={workspace({ state: 'read_only', effective_access: 'read_only', can_write: false })}
      />,
    );

    expect(screen.getByText(/solo lectura/i)).toBeTruthy();
    expect(screen.getByText(/consultar y descargar/i)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Cerrar aviso de prueba' })).toBeNull();
  });

  it('renders nothing for a workspace the backfill has not reached', () => {
    const { container } = render(
      <WorkspaceAccessBanner workspace={{ ...workspace(), entitlement: null }} />,
    );

    expect(container.innerHTML).toBe('');
  });
});

describe('WorkspacePlanCard', () => {
  it('prices a consulting room at USD 15', () => {
    render(<WorkspacePlanCard workspace={workspace()} />);

    expect(screen.getByText('USD 15 / mes')).toBeTruthy();
  });

  it('prices a clinic with its extra doctors and explains the free roles', () => {
    const clinic = workspace({
      plan_code: 'clinic',
      clinical_seats: { included: 2, active_doctors: 4, additional: 2 },
    });

    render(<WorkspacePlanCard workspace={clinic} />);

    // 21 base + 2 extra doctors at 7.
    expect(screen.getByText('USD 35 / mes')).toBeTruthy();
    expect(screen.getByText(/2 médicos adicionales/i)).toBeTruthy();
    expect(screen.getByText(/no ocupan cupo/i)).toBeTruthy();
  });

  it('mentions the bundle only when the owner qualifies', () => {
    const { rerender } = render(<WorkspacePlanCard workspace={workspace()} />);
    expect(screen.queryByText(/USD 30 al mes/i)).toBeNull();

    rerender(<WorkspacePlanCard workspace={workspace({ bundle_eligible: true })} />);
    expect(screen.getByText(/USD 30 al mes/i)).toBeTruthy();
  });

  it('shows a manual rate and unlimited seats without a published monthly price or bundle', () => {
    render(<WorkspacePlanCard workspace={workspace({
      plan_code: 'clinic_unlimited',
      clinical_seats: { included: null, active_doctors: 24, additional: 0 },
      bundle_eligible: true,
    })} />);

    expect(screen.getByText('Plan Clínica ilimitada')).toBeTruthy();
    expect(screen.getByText('Tarifa manual')).toBeTruthy();
    expect(screen.getByText(/Médicos activos: 24 · sin límite de cupos/)).toBeTruthy();
    expect(screen.queryByText(/USD 0 \/ mes|USD 21 \/ mes|USD 30 al mes/)).toBeNull();
  });
});
