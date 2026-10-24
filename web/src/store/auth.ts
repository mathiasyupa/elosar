import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { AuthUser, Doctor, LegalStatus, StaffProfile, Workspace } from '@/api/types';
import { preferredWorkspace } from '@/lib/workspaceAccess';

interface SessionPayload {
  token: string;
  user: AuthUser;
  doctor: Doctor | null;
  staffProfile: StaffProfile | null;
  workspaces: Workspace[];
}

interface AuthState {
  token: string | null;
  user: AuthUser | null;
  doctor: Doctor | null;
  staffProfile: StaffProfile | null;
  workspaces: Workspace[];
  activeWorkspaceId: number | null;
  legal: LegalStatus | null;
  /** When the current token was issued (ms); null for sessions saved before this existed. */
  tokenIssuedAt: number | null;
  /** The token a renewal replaced: same session, so the query cache stays. */
  renewedFrom: string | null;

  isAuthenticated: () => boolean;
  renewToken: (token: string) => void;
  setSession: (payload: SessionPayload) => void;
  setIdentity: (payload: { user: AuthUser; doctor: Doctor | null; staffProfile: StaffProfile | null }) => void;
  setWorkspaces: (workspaces: Workspace[]) => void;
  setActiveWorkspace: (id: number) => void;
  setLegal: (legal: LegalStatus) => void;
  logout: () => void;
}

/**
 * Auth + active-workspace state, persisted to localStorage so a page reload
 * keeps the session. The API client reads token + activeWorkspaceId straight
 * from here to set the request headers. Workspaces carry per-user role + the
 * permission set used by useWorkspaceCapabilities() to shape the UI.
 */
export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      token: null,
      user: null,
      doctor: null,
      staffProfile: null,
      workspaces: [],
      activeWorkspaceId: null,
      legal: null,
      tokenIssuedAt: null,
      renewedFrom: null,

      isAuthenticated: () => Boolean(get().token),

      renewToken: (token) => set({ token, tokenIssuedAt: Date.now(), renewedFrom: get().token }),

      setSession: ({ token, user, doctor, staffProfile, workspaces }) =>
        set({
          token,
          tokenIssuedAt: Date.now(),
          renewedFrom: null,
          user,
          doctor,
          staffProfile,
          workspaces,
          // Prefer a personal workspace (solo doctor); else the first clinic.
          // Land on a workspace the doctor can actually work in. A read-only
          // one is never dropped from the list — its records stay reachable —
          // but it is a poor place to open the app on.
          activeWorkspaceId: preferredWorkspace(workspaces)?.id ?? null,
        }),

      setIdentity: ({ user, doctor, staffProfile }) => set({ user, doctor, staffProfile }),

      setWorkspaces: (workspaces) => set({ workspaces }),

      setActiveWorkspace: (activeWorkspaceId) => set({ activeWorkspaceId }),
      setLegal: (legal) => set({ legal }),

      logout: () =>
        set({
          token: null,
          user: null,
          doctor: null,
          staffProfile: null,
          workspaces: [],
          activeWorkspaceId: null,
          legal: null,
          tokenIssuedAt: null,
          renewedFrom: null,
        }),
    }),
    {
      name: 'medico-auth',
      // `photo_url` is a signed, expiring URL (backend: App\Support\PrivateImageUrl).
      // Persisting it would rehydrate a dead link after a reload — and park a
      // capability URL in localStorage, which is exactly what moving these files
      // off the public disk was meant to stop. AppLayout's `me` query refills it
      // on mount, so dropping it here costs nothing.
      partialize: ({ doctor, ...state }) => ({
        ...state,
        doctor: doctor ? { ...doctor, photo_url: null } : null,
      }),
    },
  ),
);

/** The token saved in this browser right now, whatever this tab holds in memory. */
export function storedToken(): string | null {
  try {
    const raw = window.localStorage.getItem('medico-auth');
    return raw ? ((JSON.parse(raw) as { state?: { token?: string | null } }).state?.token ?? null) : null;
  } catch {
    return null;
  }
}

// Every tab shares one session, like any website. A patient opened in its own
// tab used to keep the session it was born with: after «Cerrar sesión» and
// another doctor signing in on the clinic computer, that tab's dead token got a
// 401 and wiped the new doctor's session too — doctors were «thrown out» all day.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === 'medico-auth') void useAuthStore.persist.rehydrate();
  });
}
