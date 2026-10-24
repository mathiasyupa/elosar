/**
 * Production error monitoring (Sentry), fully opt-in.
 *
 * The SDK is only loaded — and only initialized — when VITE_SENTRY_DSN is set
 * at build time. Without it this module is a handful of no-ops, so local dev
 * and any build made from a public checkout stay clean: no network calls, no
 * DSN baked into the bundle, no extra vendor chunk downloaded.
 *
 * The import is dynamic so the SDK lands in its own lazily-fetched chunk.
 */

type SentryModule = typeof import('@sentry/react');

const dsn = import.meta.env.VITE_SENTRY_DSN as string | undefined;
const environment = (import.meta.env.VITE_SENTRY_ENV as string | undefined) ?? import.meta.env.MODE;

export const monitoringEnabled = Boolean(dsn);

let sentry: SentryModule | null = null;

/** Loads and configures Sentry. Safe to call unconditionally; resolves immediately when disabled. */
export async function initMonitoring(): Promise<void> {
  if (!dsn) return;

  try {
    const mod = await import('@sentry/react');
    mod.init({
      dsn,
      environment,
      // Errors only. No performance tracing and no session replay: this is a
      // clinical app and the free tier budget is better spent on real crashes.
      tracesSampleRate: 0,
      // Never ship request bodies or cookies — they carry patient data.
      sendDefaultPii: false,
      beforeSend: scrub,
    });
    sentry = mod;
  } catch (error) {
    // A monitoring failure must never take the app down.
    console.warn('[monitoring] Sentry could not be initialized', error);
  }
}

/** Reports an error. Falls back to the console when monitoring is off. */
export function captureError(error: unknown, context?: Record<string, unknown>): void {
  if (sentry) {
    sentry.captureException(error, context ? { extra: context } : undefined);
    return;
  }
  console.error('[error]', error, context ?? '');
}

/** Tags the current session so a crash report says who/where without exposing patient data. */
export function setMonitoringUser(user: { id: number; role?: string; workspaceId?: number | null } | null): void {
  if (!sentry) return;
  sentry.setUser(user ? { id: String(user.id) } : null);
  sentry.setTag('workspace_id', user?.workspaceId != null ? String(user.workspaceId) : 'none');
  sentry.setTag('membership_role', user?.role ?? 'none');
}

/**
 * Strips anything that could carry patient data out of a report. Under LOPDP a
 * crash report is still a data export, so we keep the stack trace and drop the
 * payload: query strings, request bodies and response bodies.
 */
function scrub<T extends { request?: { url?: string; data?: unknown; cookies?: unknown }; extra?: Record<string, unknown> }>(
  event: T,
): T {
  if (event.request?.url) {
    const [path] = event.request.url.split('?');
    event.request.url = path;
  }
  delete event.request?.data;
  delete event.request?.cookies;
  if (event.extra) {
    delete event.extra.responseData;
    delete event.extra.requestData;
  }
  return event;
}
