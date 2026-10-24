import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/auth';
import { fetchPregnancyTimeline } from '@/api/obstetrics';

const W = 660;
const H = 190;
const PAD = { top: 12, right: 14, bottom: 26, left: 38 };
const X_MIN = 6;
const X_MAX = 42;
/** Fixed scale, unlike the curve: a pressure only reads against its absolute value. */
const Y_MIN = 50;
const Y_MAX = 180;
/**
 * Hypertension in pregnancy: systolic ≥ 140 or diastolic ≥ 90 mmHg (GPC MSP
 * "Trastornos hipertensivos del embarazo"). Drawn as a reference, not a
 * diagnosis — the doctor reads it.
 */
const SYS_LIMIT = 140;
const DIA_LIMIT = 90;

const sx = (weeks: number) => PAD.left + ((weeks - X_MIN) / (X_MAX - X_MIN)) * (W - PAD.left - PAD.right);
const sy = (v: number) => H - PAD.bottom - ((Math.min(Math.max(v, Y_MIN), Y_MAX) - Y_MIN) / (Y_MAX - Y_MIN)) * (H - PAD.top - PAD.bottom);

/**
 * La presión arterial de cada control prenatal contra la edad gestacional: lo
 * que se vigila para la preeclampsia. Sistólica y diastólica, con 140/90 marcado
 * y los controles que lo alcanzan resaltados.
 */
export function PregnancyBloodPressure({ pregnancyId }: { pregnancyId: number }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  // Same key as the curve and the calendar: one request for the three.
  const timeline = useQuery({
    queryKey: ['pregnancy-timeline', activeWorkspaceId, pregnancyId],
    queryFn: () => fetchPregnancyTimeline(pregnancyId),
  });

  const points = (timeline.data ?? []).filter(
    (p): p is typeof p & { weeks: number; bp_systolic: number; bp_diastolic: number } =>
      p.weeks != null && p.bp_systolic != null && p.bp_diastolic != null,
  );

  if (timeline.isLoading) return null;
  if (points.length === 0) {
    return <p className="preg-bp-empty">Sin presión arterial registrada en los controles todavía.</p>;
  }

  const high = points.filter((p) => p.bp_systolic >= SYS_LIMIT || p.bp_diastolic >= DIA_LIMIT);
  const last = points.at(-1)!;
  const line = (key: 'bp_systolic' | 'bp_diastolic') => points.map((p) => `${sx(p.weeks)},${sy(p[key])}`).join(' ');

  return (
    <div className="chart-scroll preg-bp">
      <p className="preg-bp-summary">
        Última: <b className="mono">{last.bp_systolic}/{last.bp_diastolic}</b> mmHg ({last.label ?? `${last.weeks} sem`})
        {high.length > 0 && (
          <span className="preg-bp-alert"> · {high.length} control{high.length === 1 ? '' : 'es'} en 140/90 o más</span>
        )}
      </p>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} role="img"
        aria-label={`Presión arterial por semana de gestación. Última ${last.bp_systolic}/${last.bp_diastolic} mmHg.`}>
        {[10, 15, 20, 25, 30, 35, 40].map((w) => (
          <g key={w}>
            <line className="preg-bp-grid" x1={sx(w)} x2={sx(w)} y1={PAD.top} y2={H - PAD.bottom} />
            <text className="preg-bp-axis" x={sx(w)} y={H - 8} textAnchor="middle">{w}</text>
          </g>
        ))}
        {[60, 90, 120, 140, 160].map((v) => (
          <text key={v} className="preg-bp-axis" x={PAD.left - 6} y={sy(v) + 3} textAnchor="end">{v}</text>
        ))}
        <line className="preg-bp-limit" x1={PAD.left} x2={W - PAD.right} y1={sy(SYS_LIMIT)} y2={sy(SYS_LIMIT)} />
        <line className="preg-bp-limit" x1={PAD.left} x2={W - PAD.right} y1={sy(DIA_LIMIT)} y2={sy(DIA_LIMIT)} />
        {points.length > 1 && <polyline className="preg-bp-line sys" points={line('bp_systolic')} />}
        {points.length > 1 && <polyline className="preg-bp-line dia" points={line('bp_diastolic')} />}
        {points.map((p) => {
          const isHigh = p.bp_systolic >= SYS_LIMIT || p.bp_diastolic >= DIA_LIMIT;
          return (
            <g key={p.record_id}>
              <circle className={`preg-bp-dot sys ${isHigh ? 'high' : ''}`.trim()} cx={sx(p.weeks)} cy={sy(p.bp_systolic)} r={isHigh ? 5.5 : 4}>
                <title>{`${p.bp_systolic}/${p.bp_diastolic} mmHg · ${p.label ?? `semana ${p.weeks}`}`}</title>
              </circle>
              <circle className={`preg-bp-dot dia ${isHigh ? 'high' : ''}`.trim()} cx={sx(p.weeks)} cy={sy(p.bp_diastolic)} r={isHigh ? 5.5 : 4}>
                <title>{`${p.bp_systolic}/${p.bp_diastolic} mmHg · ${p.label ?? `semana ${p.weeks}`}`}</title>
              </circle>
            </g>
          );
        })}
      </svg>
      <p className="preg-bp-legend">
        <span className="preg-bp-key sys" /> Sistólica
        <span className="preg-bp-key dia" /> Diastólica
        <span className="preg-bp-key limit" /> 140/90
      </p>
    </div>
  );
}
