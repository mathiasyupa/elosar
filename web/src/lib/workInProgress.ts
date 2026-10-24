import { useEncounterGuardStore } from '@/store/encounterGuard';

// Fields someone typed into. Any of them still on screen with text means a form
// left half-filled (a new patient, an invoice, a note): a reload — after a long
// absence, or to pick up a new version — would throw it away.
const typed = new Set<HTMLInputElement | HTMLTextAreaElement>();

function onInput(event: Event): void {
  const field = event.target;
  if (field instanceof HTMLTextAreaElement || (field instanceof HTMLInputElement && field.type !== 'search')) {
    typed.add(field);
  }
}

/** Start noticing typed fields. Idempotent; @returns cleanup (tests). */
export function trackTypedWork(): () => void {
  document.addEventListener('input', onInput, true);
  return () => {
    document.removeEventListener('input', onInput, true);
    typed.clear();
  };
}

/** Typed work a reload would lose: an unsaved atención or a half-filled field on screen. */
export function hasWorkInProgress(): boolean {
  if (useEncounterGuardStore.getState().hasUnsavedWork) return true;
  for (const field of typed) {
    if (!field.isConnected) typed.delete(field);
    else if (field.value.trim() !== '') return true;
  }
  return false;
}
