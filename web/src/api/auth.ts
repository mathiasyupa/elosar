import { api } from './client';
import type { AuthUser, Doctor, LegalStatus, MembershipRole, StaffProfile, Workspace } from './types';

export interface MeResponse {
  user: AuthUser;
  doctor: Doctor | null;
  staff_profile: StaffProfile | null;
  workspaces: Workspace[];
  legal: LegalStatus;
}

export interface LoginResponse extends MeResponse {
  token: string;
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/login', {
    email,
    password,
    device_name: 'web',
  });
  return data;
}

export async function logout(): Promise<void> {
  await api.post('/auth/logout');
}

export async function fetchMe(): Promise<MeResponse> {
  const { data } = await api.get<MeResponse>('/auth/me');
  return data;
}

export async function acceptTerms(): Promise<MeResponse> {
  const { data } = await api.post<MeResponse>('/auth/accept-terms', { accept_terms: true });
  return data;
}

/** Replaces an operator-issued temporary password and revokes every session. */
export async function changeTemporaryPassword(payload: {
  current_password: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>('/auth/change-temporary-password', payload);
  return data;
}

export interface InviteInfo {
  email: string;
  role: MembershipRole;
  clinic_name: string | null;
}

/**
 * Redeems a one-time impersonation code (superadmin "Impersonate" action,
 * ?code= on /impersonate) for a full session. The code is single-use and
 * expires in 5 minutes — the real bearer token is only minted server-side
 * at this call, never carried in the link itself.
 */
export async function exchangeImpersonation(code: string): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/impersonate', { code });
  return data;
}

/**
 * What the Google exchange returns when the identity is verified but has no
 * account yet. No token, on purpose: Google supplies a name and an address, and
 * a doctor still owes a cédula, a phone, a city, specialties and an acceptance
 * of the terms before there is anything to sign in to.
 */
export interface GoogleRegistrationRequired {
  requires_registration: true;
  email: string;
  name: string | null;
}

export type GoogleExchangeResponse = LoginResponse | GoogleRegistrationRequired;

export function needsRegistration(
  response: GoogleExchangeResponse,
): response is GoogleRegistrationRequired {
  return 'requires_registration' in response;
}

/**
 * Redeems the one-time code the Google callback leaves in the URL.
 *
 * Single-use and short-lived, exactly like the impersonation code: the bearer
 * token is minted server-side at this call, never carried in the redirect. The
 * one exception is an identity with no account yet — that code survives this
 * call so the sign-up wizard can present it again on submit.
 */
export async function exchangeGoogleCode(code: string): Promise<GoogleExchangeResponse> {
  const { data } = await api.post<GoogleExchangeResponse>('/auth/google/exchange', { code });
  return data;
}

/** Public lookup of a clinic invitation by token (staff onboarding screen). */
export async function fetchInvite(token: string): Promise<InviteInfo> {
  const { data } = await api.get<{ data: InviteInfo }>(`/auth/invite/${token}`);
  return data.data;
}

/** Register a staff account from an invite token; returns a full session. */
export async function registerStaff(payload: {
  token: string;
  name: string;
  phone?: string;
  password: string;
  /** Rejected without it — an invitation proves the address, not agreement. */
  accept_terms: boolean;
}): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/register/staff', payload);
  return data;
}

/**
 * Register a doctor account from a doctor-role invite. Same endpoint as staff,
 * but carries the clinical fields so the server creates a real Doctor (medical
 * license/specialty) plus the personal workspace. Returns a full session.
 */
export async function registerDoctorFromInvite(payload: {
  token: string;
  name: string;
  password: string;
  id_number: string;
  phone: string;
  city: string;
  /** First entry is the primary specialty (printed on prescriptions). */
  specialty_ids: number[];
  /** Rejected without it — an invitation proves the address, not agreement. */
  accept_terms: boolean;
}): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/register/staff', payload);
  return data;
}

/**
 * What a self-registration returns: an account, and NO session.
 *
 * Deliberate — the address has to answer before the account can be used, and
 * handing over a token here would make the check at login decorative. Accounts
 * created from an invitation are the exception and still return a session: the
 * invitation itself already proved the address.
 */
export interface RegistrationPendingResponse {
  requires_verification: true;
  email: string;
  message: string;
}

/**
 * A sign-up either parks the account until the address answers, or — when Google
 * already vouched for the address — hands over a session immediately.
 */
export type RegistrationResponse = RegistrationPendingResponse | LoginResponse;

export function isPendingVerification(
  response: RegistrationResponse,
): response is RegistrationPendingResponse {
  return 'requires_verification' in response;
}

/**
 * Credentials for a new account: either a password, or the one-time code from a
 * Google handoff. Never both, and never neither — the server enforces the same
 * rule with required_without.
 */
type SignupCredential = { password: string; google_code?: never } | { google_code: string; password?: never };

/** Self-service doctor registration; creates the personal workspace. */
export async function registerDoctor(
  payload: {
    name: string;
    email: string;
    id_number: string;
    phone: string;
    city: string;
    /** First entry is the primary specialty (printed on prescriptions). */
    specialty_ids: number[];
    /** Rejected without it — the server records which version was accepted. */
    accept_terms: boolean;
  } & SignupCredential,
): Promise<RegistrationResponse> {
  const { data } = await api.post<RegistrationResponse>('/auth/register', payload);
  return data;
}

/**
 * Self-service clinic-administrator registration (non-doctor owner). Creates a
 * staff account + the clinic they own (no personal workspace). The owner then
 * invites their team (doctors included) once the address is confirmed.
 */
export async function registerClinicAdmin(
  payload: {
    name: string;
    email: string;
    clinic_name: string;
    phone?: string;
    /** Rejected without it — the server records which version was accepted. */
    accept_terms: boolean;
  } & SignupCredential,
): Promise<RegistrationResponse> {
  const { data } = await api.post<RegistrationResponse>('/auth/register/clinic-admin', payload);
  return data;
}

/**
 * Asks for a fresh confirmation link. Like password recovery, the reply is the
 * same whatever the address, so the UI must not present it as confirmation that
 * an account exists.
 */
export async function resendVerification(email: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>('/auth/email/resend', { email });
  return data;
}

/**
 * Requests a password reset link. The server answers the same way whether or not
 * the address has an account — deliberately, so the form cannot be used to find
 * out which doctors use Elosar — so the UI must never present the reply as
 * confirmation that the address exists.
 */
export async function forgotPassword(email: string): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>('/auth/forgot-password', { email });
  return data;
}

/** Consumes a reset token and sets the new password. Revokes every existing session. */
export async function resetPassword(payload: {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<{ message: string }> {
  const { data } = await api.post<{ message: string }>('/auth/reset-password', payload);
  return data;
}

/**
 * Revokes every session, this one included.
 *
 * For a doctor whose phone was lost or stolen: until this existed, the only way
 * to evict a session they no longer controlled was to reset the password —
 * a strange thing to demand of someone whose password was never the problem.
 */
export async function logoutEverywhere(): Promise<{ message: string; revoked: number }> {
  const { data } = await api.post<{ message: string; revoked: number }>('/auth/logout-all');
  return data;
}
