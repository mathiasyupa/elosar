import { useUiStore } from '@/store/ui';
import { Icon } from '@/components/Icon';

const ICON: Record<string, string> = {
  success: 'check',
  error: 'x',
  info: 'bell',
};

/** Fixed-position toast stack. Toasts auto-dismiss from the store after 4s. */
export function Toaster() {
  const toasts = useUiStore((s) => s.toasts);
  const dismiss = useUiStore((s) => s.dismissToast);

  if (toasts.length === 0) return null;

  return (
    <div className="toaster" aria-live="polite">
      {toasts.map((toApply) => (
        <div key={toApply.id} className={`toast ${toApply.kind}`} role="status">
          <Icon name={ICON[toApply.kind] ?? 'bell'} size={16} />
          <span>{toApply.message}</span>
          <button className="icon-btn" onClick={() => dismiss(toApply.id)} aria-label="Cerrar">
            <Icon name="x" size={14} />
          </button>
        </div>
      ))}
    </div>
  );
}
