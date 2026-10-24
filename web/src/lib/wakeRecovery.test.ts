// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { bindWakeRecovery } from './wakeRecovery';
import { useEncounterGuardStore } from '@/store/encounterGuard';

describe('bindWakeRecovery', () => {
  const reload = vi.fn();
  let unbind: () => void;
  let client: QueryClient;

  beforeEach(() => {
    vi.useFakeTimers();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, reload } });
    client = new QueryClient();
    unbind = bindWakeRecovery(client);
  });

  afterEach(() => {
    unbind();
    vi.useRealTimers();
    reload.mockReset();
    useEncounterGuardStore.getState().setHasUnsavedWork(false);
  });

  it('reloads the page after the laptop slept for a long time', () => {
    vi.setSystemTime(Date.now() + 2 * 60 * 60_000); // two hours asleep: no ticks
    vi.advanceTimersByTime(30_000);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('does not reload during normal use', () => {
    vi.advanceTimersByTime(10 * 60_000);
    expect(reload).not.toHaveBeenCalled();
  });

  it('keeps an atención with unsaved work and refetches instead', () => {
    useEncounterGuardStore.getState().setHasUnsavedWork(true);
    const reset = vi.spyOn(client, 'resetQueries');
    vi.setSystemTime(Date.now() + 2 * 60 * 60_000);
    vi.advanceTimersByTime(30_000);
    expect(reload).not.toHaveBeenCalled();
    expect(reset).toHaveBeenCalled();
  });
});

describe('bindWakeRecovery with a half-filled form', () => {
  it('keeps a form someone typed into instead of reloading', () => {
    vi.useFakeTimers();
    const reload = vi.fn();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, reload } });
    const unbind = bindWakeRecovery(new QueryClient());
    const input = document.createElement('input');
    document.body.appendChild(input);
    input.value = 'Juan Pérez';
    input.dispatchEvent(new Event('input', { bubbles: true }));

    vi.setSystemTime(Date.now() + 2 * 60 * 60_000);
    vi.advanceTimersByTime(30_000);
    expect(reload).not.toHaveBeenCalled();

    input.remove();
    unbind();
    vi.useRealTimers();
  });
});
