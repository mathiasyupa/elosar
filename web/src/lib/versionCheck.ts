import { hasWorkInProgress } from './workInProgress';

const CHECK_EVERY_MS = 5 * 60_000;

/**
 * Tabs left open for days kept running the build they were born with, so a fix
 * reached the clinic only when someone thought of reloading — and an old tab
 * could undo it (an old one signed everybody out). Every tab now asks for
 * /version.json now and then and reloads itself when a newer build is out.
 *
 * Never over typed work: with an unsaved atención or a half-filled form on
 * screen it waits for the next check. A reload keeps the session (the token
 * lives in localStorage), so it only costs a second.
 *
 * @returns cleanup (tests).
 */
export function bindVersionCheck(current: string = __BUILD_ID__): () => void {
  let busy = false;

  const check = async () => {
    if (busy || document.visibilityState !== 'visible') return;
    busy = true;
    try {
      const response = await fetch(`/version.json?t=${Date.now()}`, { cache: 'no-store' });
      if (!response.ok) return; // dev server, or a hiccup: try next time
      const { build } = (await response.json()) as { build?: string };
      if (build && build !== current && !hasWorkInProgress()) window.location.reload();
    } catch {
      // Offline: the next check will tell.
    } finally {
      busy = false;
    }
  };

  const onVisibility = () => {
    if (document.visibilityState === 'visible') void check();
  };
  const timer = window.setInterval(() => void check(), CHECK_EVERY_MS);
  document.addEventListener('visibilitychange', onVisibility);

  return () => {
    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
  };
}
