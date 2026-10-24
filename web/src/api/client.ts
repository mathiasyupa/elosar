import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { storedToken, useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import type { Workspace } from '@/api/types';

const baseURL = `${import.meta.env.VITE_API_URL ?? 'http://localhost'}/api/v1`;

export const api = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
  // Without a limit, a request sent over a connection that died while the
  // laptop slept never settles: «Crear paciente» spun forever with no error.
  // Generous enough for a photo upload or a PDF on a slow clinic connection.
  timeout: 60_000,
});

// Inject the bearer token and the active workspace on every request. Reading
// from the store (not React) keeps this usable outside components.
api.interceptors.request.use((config) => {
  const { token, activeWorkspaceId } = useAuthStore.getState();

  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  if (activeWorkspaceId) {
    config.headers['X-Workspace-Id'] = String(activeWorkspaceId);
  }

  return config;
});

type RetriableConfig = InternalAxiosRequestConfig & { _authRetried?: boolean };

// One check for a burst of 401s: ten requests failing together ask once.
let sessionCheck: { token: string; result: Promise<boolean> } | null = null;

/** Whether the server still accepts `token`. Only a 401 says no. */
function sessionStillValid(token: string): Promise<boolean> {
  if (sessionCheck?.token === token) return sessionCheck.result;
  const result = axios
    .get(`${baseURL}/auth/me`, { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, timeout: 20_000 })
    .then(() => true)
    .catch((error) => error?.response?.status !== 401);
  sessionCheck = { token, result };
  void result.finally(() => {
    window.setTimeout(() => {
      if (sessionCheck?.result === result) sessionCheck = null;
    }, 5_000);
  });
  return result;
}

// A 401 signs the doctor out only once the server confirms that the session
// this browser holds is really gone (ADR-021). Before, any 401 did — one from a
// tab left with an older token, or a single odd response, sent everyone in the
// browser back to the login screen in the middle of their work.
async function handleUnauthorized(error: AxiosError): Promise<unknown> {
  const config = error.config as RetriableConfig | undefined;
  const sentWith = String(config?.headers?.Authorization ?? '').replace(/^Bearer /, '');
  if (!config || !sentWith || config._authRetried) {
    if (sentWith && sentWith === useAuthStore.getState().token && !(await sessionStillValid(sentWith))) {
      useAuthStore.getState().logout();
    }
    return Promise.reject(error);
  }

  // Another tab signed in (or the session moved on): adopt it and retry.
  const saved = storedToken();
  if (saved && saved !== sentWith) {
    if (saved !== useAuthStore.getState().token) await useAuthStore.persist.rehydrate();
    config._authRetried = true;
    config.headers.Authorization = `Bearer ${saved}`;
    return api(config);
  }

  if (await sessionStillValid(sentWith)) {
    config._authRetried = true;
    return api(config);
  }
  if (sentWith === useAuthStore.getState().token) useAuthStore.getState().logout();
  return Promise.reject(error);
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;

    if (status === 401 && !String(error?.config?.url ?? '').includes('/auth/logout')) {
      return handleUnauthorized(error);
    }

    // 423: the workspace went read-only while this tab was open. Say so in
    // plain Spanish instead of a generic failure, and refresh the workspace
    // list so the banner and disabled controls catch up — otherwise the tab
    // keeps offering actions that will not work.
    if (status === 423 && error?.response?.data?.code === 'workspace_read_only') {
      useUiStore
        .getState()
        .toast(
          'Este espacio está en modo de solo lectura. Puedes revisar la información y los documentos existentes.',
          'error',
        );

      void refreshWorkspaces();
    }

    // 422 with this code is the clinical seat limit, which reads as a plan
    // matter rather than a validation error in the form.
    if (status === 422 && error?.response?.data?.code === 'clinical_seat_limit_reached') {
      useUiStore.getState().toast(error.response.data.message, 'error');
    }

    return Promise.reject(error);
  },
);

/** Re-read the accessible workspaces so a stale tab stops looking writable. */
async function refreshWorkspaces(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Workspace[] }>('/workspaces');
    useAuthStore.getState().setWorkspaces(data.data);
  } catch {
    // Best effort: the toast already told the doctor what happened.
  }
}
