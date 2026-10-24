// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import axios, { AxiosError, AxiosHeaders, type InternalAxiosRequestConfig } from 'axios';
import { api } from './client';
import { useAuthStore } from '@/store/auth';

/** Runs the response interceptor on a 401 for a request sent with `token`. */
function unauthorizedFor(token: string) {
  const config = { url: '/patients', headers: new AxiosHeaders({ Authorization: `Bearer ${token}` }) } as InternalAxiosRequestConfig;
  const error = new AxiosError('401', '401', config, null, {
    status: 401, data: {}, statusText: '', headers: {}, config,
  });
  const handlers = (api.interceptors.response as unknown as { handlers: { rejected: (e: unknown) => Promise<unknown> }[] }).handlers;
  return handlers[0].rejected(error).catch(() => undefined);
}

/** The /auth/me confirmation answers with `status`. */
function serverSays(status: number) {
  return vi.spyOn(axios, 'get').mockImplementation(() =>
    status === 200
      ? Promise.resolve({ data: {} })
      : Promise.reject(Object.assign(new Error('x'), { response: { status } })),
  );
}

afterEach(() => {
  vi.restoreAllMocks();
  useAuthStore.setState({ token: null });
  window.localStorage.clear();
});

describe('401 handling', () => {
  it('signs out only when the server confirms the session is gone', async () => {
    useAuthStore.setState({ token: 'maria' });
    serverSays(401);

    await unauthorizedFor('maria');

    expect(useAuthStore.getState().token).toBeNull();
  });

  it('keeps the doctor signed in when the session is still good (one odd 401)', async () => {
    useAuthStore.setState({ token: 'ana' });
    serverSays(200);
    vi.spyOn(api.defaults, 'adapter', 'get').mockReturnValue(() => Promise.resolve({ data: {}, status: 200, statusText: '', headers: {}, config: {} as InternalAxiosRequestConfig }));

    await unauthorizedFor('ana');

    expect(useAuthStore.getState().token).toBe('ana');
  });

  it('keeps the new doctor signed in when an old tab is rejected', async () => {
    useAuthStore.setState({ token: 'maria' }); // this stale tab still remembers María
    // Another tab then signed in as Pedro: this browser saved his session.
    window.localStorage.setItem('medico-auth', JSON.stringify({ state: { token: 'pedro' }, version: 0 }));
    vi.spyOn(api.defaults, 'adapter', 'get').mockReturnValue(() => Promise.resolve({ data: {}, status: 200, statusText: '', headers: {}, config: {} as InternalAxiosRequestConfig }));

    await unauthorizedFor('maria');

    expect(useAuthStore.getState().token).toBe('pedro');
    expect(JSON.parse(window.localStorage.getItem('medico-auth')!).state.token).toBe('pedro');
  });
});
