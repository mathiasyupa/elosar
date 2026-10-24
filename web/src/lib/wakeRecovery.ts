import type { QueryClient } from '@tanstack/react-query';
import { hasWorkInProgress, trackTypedWork } from './workInProgress';

/** Longer than this away (laptop closed, tab forgotten) and the page restarts. */
const LONG_ABSENCE_MS = 20 * 60_000;
const TICK_MS = 30_000;

/**
 * A tab left open for hours — the laptop closed overnight, reopened at the
 * clinic — came back with dead connections and a bundle older than the last
 * deploy: the summary failed to load and «Crear paciente» spun forever until
 * someone thought of reloading. Coming back from a long absence now reloads the
 * page by itself, unless something typed is still on screen — an atención the
 * server does not have, a half-filled patient or invoice form — in which case
 * everything is refetched instead and nothing is lost.
 *
 * A sleeping laptop does not always report the tab as hidden, so the absence is
 * also measured as a gap in a timer that stops ticking while the machine sleeps.
 *
 * @returns cleanup (tests).
 */
export function bindWakeRecovery(queryClient: QueryClient): () => void {
  let lastSeen = Date.now();

  const untrack = trackTypedWork();

  const recover = () => {
    const away = Date.now() - lastSeen;
    lastSeen = Date.now();
    if (away < LONG_ABSENCE_MS) return;
    if (hasWorkInProgress()) {
      void queryClient.resetQueries();
      return;
    }
    window.location.reload();
  };

  const onVisibility = () => {
    if (document.visibilityState === 'visible') recover();
    else lastSeen = Date.now();
  };
  // Back online after the Wi-Fi dropped: retry what failed meanwhile.
  const onOnline = () => void queryClient.refetchQueries({ type: 'active' });

  const timer = window.setInterval(() => {
    if (document.visibilityState === 'visible') recover();
  }, TICK_MS);
  document.addEventListener('visibilitychange', onVisibility);
  window.addEventListener('online', onOnline);

  return () => {
    untrack();
    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('online', onOnline);
  };
}
