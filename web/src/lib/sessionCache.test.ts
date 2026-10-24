import { QueryClient } from '@tanstack/react-query';
import { afterEach, describe, expect, it } from 'vitest';
import { useAuthStore } from '@/store/auth';
import { bindSessionCache } from './sessionCache';

afterEach(() => useAuthStore.setState({ token: null }));

describe('session cache', () => {
  it('forgets the previous doctor when another one signs in', () => {
    useAuthStore.setState({ token: 'alfredo' });
    const qc = new QueryClient();
    const unbind = bindSessionCache(qc);
    qc.setQueryData(['profile'], { name: 'Alfredo' });

    useAuthStore.setState({ token: null }); // sale Alfredo
    useAuthStore.setState({ token: 'maria' }); // entra María

    expect(qc.getQueryData(['profile'])).toBeUndefined();
    unbind();
  });

  it('keeps the cache while the same session goes on', () => {
    useAuthStore.setState({ token: 'maria' });
    const qc = new QueryClient();
    const unbind = bindSessionCache(qc);
    qc.setQueryData(['profile'], { name: 'María' });

    useAuthStore.setState({ activeWorkspaceId: 10 });

    expect(qc.getQueryData(['profile'])).toEqual({ name: 'María' });
    unbind();
  });
});

describe('session cache on token renewal', () => {
  it('keeps the cache when the same session renews its token', () => {
    useAuthStore.setState({ token: 'maria-1' });
    const qc = new QueryClient();
    const unbind = bindSessionCache(qc);
    qc.setQueryData(['profile'], { name: 'María' });

    useAuthStore.getState().renewToken('maria-2');

    expect(qc.getQueryData(['profile'])).toEqual({ name: 'María' });
    unbind();
  });
});
