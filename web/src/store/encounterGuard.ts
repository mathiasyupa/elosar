import { create } from 'zustand';

interface EncounterGuardState {
  hasUnsavedWork: boolean;
  setHasUnsavedWork: (value: boolean) => void;
  /** Bumped by «Nueva atención»: the expediente keys the kept-alive encounter
   *  on it, so a new visit starts from a clean screen instead of resetting
   *  twenty fields one by one. */
  generation: number;
  startNewEncounter: () => void;
  /** Set by «Nueva atención» so the fresh screen ignores the `?record=` it may
   *  still see in the URL on its first render; consumed once on mount. */
  discardEntryQuery: boolean;
  consumeDiscardEntryQuery: () => boolean;
}

/** What the rest of the expediente needs to know about the open atención.
 *  `hasUnsavedWork` means "typed work the server does not have and will not
 *  get on its own": a visit never saved, or a save that failed. Once a visit
 *  is saved, later edits autosave and flush before leaving, so moving around
 *  no longer asks. Not persisted: a full reload is `beforeunload`'s job. */
export const useEncounterGuardStore = create<EncounterGuardState>((set, get) => ({
  hasUnsavedWork: false,
  setHasUnsavedWork: (value) => set({ hasUnsavedWork: value }),
  generation: 0,
  startNewEncounter: () =>
    set((s) => ({ generation: s.generation + 1, hasUnsavedWork: false, discardEntryQuery: true })),
  discardEntryQuery: false,
  consumeDiscardEntryQuery: () => {
    const discard = get().discardEntryQuery;
    if (discard) set({ discardEntryQuery: false });
    return discard;
  },
}));

/** Confirm navigation that would leave the patient and discard encounter work. */
export function confirmLeaveEncounter(): boolean {
  return !useEncounterGuardStore.getState().hasUnsavedWork ||
    window.confirm('La atención tiene cambios sin guardar. Si sales ahora se perderán. ¿Salir?');
}
