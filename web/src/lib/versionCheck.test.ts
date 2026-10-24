// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bindVersionCheck } from './versionCheck';
import { trackTypedWork } from './workInProgress';

describe('bindVersionCheck', () => {
  const reload = vi.fn();
  let cleanups: (() => void)[] = [];

  beforeEach(() => {
    vi.useFakeTimers();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, reload } });
  });

  afterEach(() => {
    cleanups.forEach((fn) => fn());
    cleanups = [];
    vi.useRealTimers();
    vi.restoreAllMocks();
    reload.mockReset();
    document.body.innerHTML = '';
  });

  const serverBuild = (build: string) =>
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ build })));

  it('reloads a tab left on an old build', async () => {
    serverBuild('new');
    cleanups.push(bindVersionCheck('old'));
    await vi.advanceTimersByTimeAsync(5 * 60_000);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('stays put on the current build', async () => {
    serverBuild('same');
    cleanups.push(bindVersionCheck('same'));
    await vi.advanceTimersByTimeAsync(5 * 60_000);
    expect(reload).not.toHaveBeenCalled();
  });

  it('never reloads over a half-filled form', async () => {
    serverBuild('new');
    cleanups.push(trackTypedWork(), bindVersionCheck('old'));
    const input = document.createElement('input');
    document.body.appendChild(input);
    input.value = 'Rosa Cruz';
    input.dispatchEvent(new Event('input', { bubbles: true }));

    await vi.advanceTimersByTimeAsync(5 * 60_000);
    expect(reload).not.toHaveBeenCalled();
  });
});
