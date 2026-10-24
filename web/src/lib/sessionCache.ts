import type { QueryClient } from '@tanstack/react-query';
import { useAuthStore } from '@/store/auth';

/**
 * Empties the query cache whenever the session changes hands — sign out, a new
 * sign-in, «Cambiar de médico». Query keys are not per user (`['profile']`,
 * `['appointments', ws, …]`), so without this the next doctor on a shared
 * clinic computer saw the previous one's profile, agenda and permissions until
 * they reloaded the page.
 *
 * @returns the unsubscribe function (tests).
 */
export function bindSessionCache(queryClient: QueryClient): () => void {
  let token = useAuthStore.getState().token;

  return useAuthStore.subscribe((state) => {
    if (state.token === token) return;
    // A daily token renewal is the same doctor carrying on: keep the cache.
    const renewed = token !== null && state.token !== null && state.renewedFrom === token;
    token = state.token;
    if (!renewed) queryClient.clear();
  });
}
