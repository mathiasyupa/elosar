export function Spinner({ label }: { label?: string }) {
  return (
    <div className="spinner" role="status" aria-live="polite">
      <i />
      {label && <span>{label}</span>}
      {!label && <span className="sr-only">Cargando…</span>}
    </div>
  );
}
