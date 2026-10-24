import { useQueryClient } from '@tanstack/react-query';
import { Icon } from '@/components/Icon';

interface QueryErrorProps {
  /** The error thrown by the query — used to tell apart network / permission / server failures. */
  error?: unknown;
  /** Re-runs the query. Pass the `refetch` returned by useQuery. */
  onRetry?: () => void;
  /** Names what failed to load, e.g. "las facturas". */
  area?: string;
  /** `inline` sits inside an existing card; `block` brings its own padding. */
  variant?: 'inline' | 'block';
  /** True while the retry is in flight, so the button can show progress. */
  retrying?: boolean;
  /**
   * The query is paused (TanStack `isPaused`): the request was never sent and
   * no error exists, so without this the screen would render nothing at all.
   */
  paused?: boolean;
}

/** Reads the HTTP status off an axios-style error without importing axios here. */
function statusOf(error: unknown): number | undefined {
  if (typeof error !== 'object' || error === null) return undefined;
  const response = (error as { response?: { status?: number } }).response;
  return response?.status;
}

/**
 * Turns a failed query into an explanation plus a way out. Replaces the old
 * one-line red text, which told the doctor something broke but left them stuck.
 */
export function QueryError({
  error,
  onRetry,
  area,
  variant = 'block',
  retrying = false,
  paused = false,
}: QueryErrorProps) {
  const queryClient = useQueryClient();
  const status = statusOf(error);
  const offline = typeof navigator !== 'undefined' && !navigator.onLine;

  /**
   * A paused query cannot be revived with `refetch()` — the retryer is parked
   * and simply stays parked, which is what made this state a dead end. Clearing
   * the cache entry drops that retryer and lets the mounted screens fetch from
   * scratch, so the button actually does something.
   */
  const retry = () => {
    if (paused) void queryClient.resetQueries();
    else onRetry?.();
  };

  let message: string;
  let hint: string | null;
  let canRetry = Boolean(onRetry) || paused;

  if (paused && !error) {
    message = 'No hay conexión con el servidor';
    hint = 'La petición quedó en espera. Comprueba tu conexión y pulsa reintentar.';
  } else if (offline) {
    message = 'Sin conexión a internet';
    hint = 'Revisa tu conexión y vuelve a intentar.';
  } else if (status === 403) {
    message = 'No tienes permiso para ver esta información';
    hint = 'Si crees que deberías tenerlo, pídeselo al propietario de la clínica.';
    canRetry = false;
  } else if (status === 404) {
    message = area ? `No encontramos ${area}` : 'No encontramos esta información';
    hint = 'Es posible que se haya eliminado o que el enlace ya no sea válido.';
    canRetry = false;
  } else if (status && status >= 500) {
    message = area ? `No pudimos cargar ${area}` : 'No pudimos cargar la información';
    hint = 'El servidor tuvo un problema. Vuelve a intentar en unos segundos.';
  } else {
    message = area ? `No pudimos cargar ${area}` : 'No pudimos cargar la información';
    hint = 'Puede ser un problema temporal de conexión.';
  }

  return (
    <div className={`qerror ${variant === 'block' ? 'qerror-block' : ''}`.trim()} role="alert">
      <Icon name="alert" size={variant === 'block' ? 26 : 18} />
      <div className="qerror-body">
        <p className="qerror-msg">{message}</p>
        {hint && <p className="qerror-hint">{hint}</p>}
      </div>
      {canRetry && (
        <button type="button" className="btn sm" onClick={retry} disabled={retrying}>
          <Icon name="refresh" size={15} />
          {retrying ? 'Reintentando…' : 'Reintentar'}
        </button>
      )}
    </div>
  );
}
