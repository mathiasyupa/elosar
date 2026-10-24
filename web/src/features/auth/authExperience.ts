import type { LoginResponse } from '@/api/auth';
import type { LegalStatus } from '@/api/types';

export function requiresTermsAcceptance(legal: LegalStatus | null | undefined): boolean {
  return legal?.terms_acceptance_required === true;
}

const SAFE_REGISTRATION_ERROR =
  'No pudimos crear la cuenta con estos datos. Revisa la información o inicia sesión si ya tienes acceso.';

export function isUsableAuthResponse(response: LoginResponse): boolean {
  return Boolean(response.token && response.user && response.workspaces.length > 0);
}

export function getSafeRegistrationError(_backendMessage?: string): string {
  return SAFE_REGISTRATION_ERROR;
}
