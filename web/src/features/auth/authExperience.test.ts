import { describe, expect, it } from 'vitest';
import type { LoginResponse } from '@/api/auth';
import { getSafeRegistrationError, isUsableAuthResponse, requiresTermsAcceptance } from './authExperience';

const validResponse = {
  token: 'token',
  user: { id: 1 },
  doctor: null,
  staff_profile: null,
  workspaces: [{ id: 10 }],
} as LoginResponse;

describe('auth experience safeguards', () => {
  it('routes a signed-in account with outdated terms to acceptance before the dashboard', () => {
    expect(requiresTermsAcceptance({ terms_acceptance_required: true, terms_version: '1.0' })).toBe(true);
  });
  it('requires a usable workspace before completing authentication', () => {
    expect(isUsableAuthResponse(validResponse)).toBe(true);
    expect(isUsableAuthResponse({ ...validResponse, workspaces: [] })).toBe(false);
    expect(isUsableAuthResponse({ ...validResponse, token: '' })).toBe(false);
  });

  it('does not expose backend uniqueness details during registration', () => {
    const backendMessage = 'El correo ya ha sido registrado.';
    const message = getSafeRegistrationError(backendMessage);

    expect(message).toBe(
      'No pudimos crear la cuenta con estos datos. Revisa la información o inicia sesión si ya tienes acceso.',
    );
    expect(message).not.toContain('correo');
  });
});
