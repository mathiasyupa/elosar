import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import { MotionConfig } from 'framer-motion';
import { App } from './App';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { initMonitoring } from '@/lib/monitoring';
import { bindSessionCache } from '@/lib/sessionCache';
import { bindWakeRecovery } from '@/lib/wakeRecovery';
import { bindVersionCheck } from '@/lib/versionCheck';
import './index.css';
import './styles/themes.css';
import './store/theme';

// No-op unless VITE_SENTRY_DSN is configured.
void initMonitoring();

/**
 * Retrying a 4xx never helps — the token is bad, the permission is missing or
 * the record is gone — and it leaves the doctor watching a spinner for three
 * round trips before seeing the real message. Retry only network/5xx failures.
 */
function shouldRetry(failureCount: number, error: unknown): boolean {
  const status = (error as { response?: { status?: number } } | null)?.response?.status;
  if (status !== undefined && status >= 400 && status < 500) return false;
  return failureCount < 2;
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: shouldRetry,
      // Una clínica trabaja con varias personas y varias pestañas a la vez: la
      // recepcionista agenda mientras el médico atiende y caja cobra. Al volver
      // a una pestaña, lo que se ve debe ser de ahora, no de hace media hora.
      // `staleTime` evita que esto dispare una petición por cada alt-tab: solo
      // se refresca lo que ya lleva 30 s sin actualizarse.
      refetchOnWindowFocus: true,
      staleTime: 30_000,
      // Without this, a failed request can leave the query "paused" (pending +
      // fetchStatus 'paused') whenever the browser's online signal says there
      // is no connection: isLoading AND isError are both false, so the screen
      // renders nothing at all and never recovers. The browser's signal is not
      // trustworthy — a reachable network with an unreachable API looks the
      // same — so always attempt the request and report an honest failure.
      networkMode: 'always',
    },
    mutations: {
      // A failed write is surfaced by the calling screen; never retry silently,
      // a duplicated invoice or prescription is worse than an error message.
      retry: false,
      // Same reasoning, and more urgent: a paused mutation looks to the doctor
      // like the invoice saved when nothing was sent.
      networkMode: 'always',
    },
  },
});

// A new session never shows the previous user's cached data.
bindSessionCache(queryClient);
// A tab reopened after hours (laptop closed) restarts instead of hanging.
bindWakeRecovery(queryClient);
// Tabs left on an old build pick up the new one by themselves (ADR-021).
if (import.meta.env.PROD) bindVersionCheck();

if (import.meta.env.DEV) {
  // Handy when debugging cache/fetch states from the browser console.
  (window as unknown as { __qc: QueryClient }).__qc = queryClient;
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    {/* Last line of defence: catches anything the per-section boundaries miss
        (providers, router, the layout shell itself) so the worst case is a
        readable "recargar" screen instead of a blank page. */}
    <ErrorBoundary boundary="root" variant="page">
      <QueryClientProvider client={queryClient}>
        <MotionConfig reducedMotion="user">
          <BrowserRouter>
            <App />
          </BrowserRouter>
        </MotionConfig>
      </QueryClientProvider>
    </ErrorBoundary>
  </React.StrictMode>,
);
