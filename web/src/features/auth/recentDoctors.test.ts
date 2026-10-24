// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { recentDoctors, rememberDoctor } from './recentDoctors';

afterEach(() => {
  localStorage.clear();
  vi.restoreAllMocks();
});

describe('recent doctors on this computer', () => {
  it('lists the latest doctor first, once', async () => {
    await rememberDoctor({ name: 'ANA TORRES', email: 'ana@x.ec', photo_url: null });
    await rememberDoctor({ name: 'JUAN PEREZ', email: 'juan@x.ec', photo_url: null });
    await rememberDoctor({ name: 'ANA TORRES', email: 'ana@x.ec', photo_url: null });

    expect(recentDoctors().map((d) => d.email)).toEqual(['ana@x.ec', 'juan@x.ec']);
  });

  it('copies the photo once and reuses it on later sign-ins', async () => {
    localStorage.setItem('elosar-recent-doctors', JSON.stringify([{ name: 'B', email: 'b@x.ec', photo: 'data:image/png;base64,AA' }]));
    const fetchSpy = vi.spyOn(globalThis, 'fetch');

    await rememberDoctor({ name: 'B', email: 'b@x.ec', photo_url: 'https://signed.example/b.png' });

    expect(fetchSpy).not.toHaveBeenCalled();
    expect(recentDoctors()[0].photo).toBe('data:image/png;base64,AA');
  });
});
