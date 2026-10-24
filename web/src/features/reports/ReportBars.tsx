export type BarTone = 'accent' | 'green' | 'amber' | 'blue' | 'violet';

export interface BarDatum {
  label: string;
  value: number;
  /** Shown instead of the raw value (a money amount, a percentage…). */
  display?: string;
  /** Quiet detail after the label ('· 3 líneas · 20 %'). */
  hint?: string;
}

/**
 * Ranked horizontal bars: label, track, value. The fill is an SVG rect whose
 * width comes from the data — the repo has no inline styles, and an attribute
 * on an SVG is not one.
 *
 * Empty data collapses to a single quiet line instead of a whole empty card.
 */
export function ReportBars({
  data,
  tone = 'accent',
  empty = 'Sin datos en el período.',
  label,
}: {
  data: BarDatum[];
  tone?: BarTone;
  empty?: string;
  /** Accessible name of the list. */
  label?: string;
}) {
  if (data.length === 0) return <p className="rbar-empty">{empty}</p>;
  const max = Math.max(1, ...data.map((d) => d.value));

  return (
    <ul className={`rbar-list tone-${tone}`} aria-label={label}>
      {data.map((d) => (
        <li key={d.label} className="rbar-row">
          <span className="rbar-label" title={d.hint ? `${d.label} · ${d.hint}` : d.label}>
            {d.label}
            {d.hint && <span className="muted"> · {d.hint}</span>}
          </span>
          <svg className="rbar-track" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
            <rect className="rbar-bg" x="0" y="0" width="100" height="8" rx="1.5" ry="4" />
            <rect className="rbar-fill" x="0" y="0" width={Math.max(1.5, (d.value / max) * 100)} height="8" rx="1.5" ry="4" />
          </svg>
          <b className="rbar-value mono">{d.display ?? d.value}</b>
        </li>
      ))}
    </ul>
  );
}

/**
 * Two parts of one whole as a single bar (new vs returning patients…). The
 * first part takes the tone, the second stays neutral.
 */
export function SplitBar({
  first,
  second,
  label,
}: {
  first: { label: string; value: number };
  second: { label: string; value: number };
  label: string;
}) {
  const total = first.value + second.value;
  const share = total > 0 ? (first.value / total) * 100 : 0;
  const pct = (n: number) => (total > 0 ? `${Math.round((n / total) * 100)} %` : '—');

  return (
    <div className="rsplit">
      <svg className="rsplit-bar" viewBox="0 0 100 10" preserveAspectRatio="none" role="img" aria-label={label}>
        <rect className="rsplit-second" x="0" y="0" width="100" height="10" rx="5" />
        {share > 0 && <rect className="rsplit-first" x="0" y="0" width={share} height="10" rx="5" />}
      </svg>
      <div className="rsplit-legend">
        <span><i className="rsplit-key first" />{first.label} <b className="mono">{first.value}</b> <span className="muted">{pct(first.value)}</span></span>
        <span><i className="rsplit-key second" />{second.label} <b className="mono">{second.value}</b> <span className="muted">{pct(second.value)}</span></span>
      </div>
    </div>
  );
}
