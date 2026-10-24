import { afterEach, describe, expect, it } from 'vitest';
import { useEncounterGuardStore } from './encounterGuard';

afterEach(() => {
  useEncounterGuardStore.setState({ hasUnsavedWork: false });
});

describe('useEncounterGuardStore', () => {
  it('starts with no unsaved work', () => {
    expect(useEncounterGuardStore.getState().hasUnsavedWork).toBe(false);
  });

  it('flips when the encounter reports unsaved fields', () => {
    useEncounterGuardStore.getState().setHasUnsavedWork(true);
    expect(useEncounterGuardStore.getState().hasUnsavedWork).toBe(true);

    useEncounterGuardStore.getState().setHasUnsavedWork(false);
    expect(useEncounterGuardStore.getState().hasUnsavedWork).toBe(false);
  });
});
