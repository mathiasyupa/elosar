import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/auth';
import { fetchPregnancyTimeline } from '@/api/obstetrics';
import { formatDate } from '@/i18n/es';
import type { Pregnancy } from '@/api/types';

/** The track runs to 42 weeks: post-term has to have somewhere to be. */
const TOTAL_WEEKS = 42;
const W = 560;
const PAD_X = 10;
const TRACK_Y = 26;
const TRACK_H = 16;

const TRIMESTERS = [
  { label: '1.er trimestre', from: 0, to: 13 },
  { label: '2.º trimestre', from: 13, to: 27 },
  { label: '3.er trimestre', from: 27, to: TOTAL_WEEKS },
];

const SCALE = [0, 13, 20, 28, 37, 40];

const x = (weeks: number) =>
  PAD_X + (Math.min(Math.max(weeks, 0), TOTAL_WEEKS) / TOTAL_WEEKS) * (W - PAD_X * 2);

/**
 * El embarazo en una línea de 40 semanas: los tres trimestres, dónde está hoy,
 * cada control prenatal que se hizo (de la misma línea de tiempo que dibuja la
 * curva) y las dos fechas que se planifican — la cesárea electiva a las 39 y la
 * FPP a las 40. Solo hechos registrados: no propone cuándo deberían ser los
 * controles, porque eso es criterio de la norma y no de la pantalla.
 *
 * SVG con atributos, como la curva: las posiciones salen de los datos y el
 * repo no admite estilos inline.
 */
export function PregnancyCalendar({ pregnancy }: { pregnancy: Pregnancy }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  // Same key as PregnancyCurve: one request for both.
  const timeline = useQuery({
    queryKey: ['pregnancy-timeline', activeWorkspaceId, pregnancy.id],
    queryFn: () => fetchPregnancyTimeline(pregnancy.id),
  });

  const age = pregnancy.gestational_age;
  const today = age.is_valid && age.weeks != null ? age.weeks + (age.remainder_days ?? 0) / 7 : null;
  const controls = (timeline.data ?? []).filter((p) => p.weeks != null);
  const last = controls.at(-1);

  const summary =
    controls.length === 0
      ? 'Sin controles registrados todavía'
      : `${controls.length} control${controls.length === 1 ? '' : 'es'}${last?.label ? ` · último ${last.label}` : ''}${last?.date ? ` (${formatDate(last.date)})` : ''}`;

  return (
    <section className="preg-cal" aria-label="Calendario del embarazo">
      <div className="preg-cal-head">
        <h4>Calendario del embarazo</h4>
        <span className="preg-cal-sub">{summary}</span>
      </div>

      <svg
        className="preg-cal-svg"
        viewBox={`0 0 ${W} 70`}
        role="img"
        aria-label={
          today != null
            ? `Semana ${age.weeks}+${age.remainder_days} de 40. ${summary}.`
            : summary
        }
      >
        {TRIMESTERS.map((t, i) => (
          <g key={t.label}>
            <rect
              className={`preg-cal-tri t${i + 1}`}
              x={x(t.from)}
              y={TRACK_Y}
              width={x(t.to) - x(t.from) - 2}
              height={TRACK_H}
              rx={4}
            />
            <text className="preg-cal-tri-label" x={x(t.from) + 6} y={TRACK_Y - 8}>{t.label}</text>
          </g>
        ))}
        {today != null && (
          <rect className="preg-cal-progress" x={x(0)} y={TRACK_Y} width={x(today) - x(0)} height={TRACK_H} rx={4} />
        )}
        {controls.map((p) => (
          <circle key={p.record_id} className="preg-cal-control" cx={x(p.weeks!)} cy={TRACK_Y + TRACK_H / 2} r={5}>
            <title>{`Control ${p.label ?? `${p.weeks} sem`}${p.date ? ` · ${formatDate(p.date)}` : ''}`}</title>
          </circle>
        ))}
        {pregnancy.elective_cesarean_date && (
          <g className="preg-cal-flag fpc">
            <line x1={x(39)} x2={x(39)} y1={TRACK_Y - 4} y2={TRACK_Y + TRACK_H + 4} />
            <title>{`Cesárea electiva · ${formatDate(pregnancy.elective_cesarean_date)}`}</title>
          </g>
        )}
        <g className="preg-cal-flag fpp">
          <line x1={x(40)} x2={x(40)} y1={TRACK_Y - 4} y2={TRACK_Y + TRACK_H + 4} />
          <title>{pregnancy.edd_date ? `FPP · ${formatDate(pregnancy.edd_date)}` : 'FPP'}</title>
        </g>
        {today != null && (
          <g className="preg-cal-today">
            <line x1={x(today)} x2={x(today)} y1={TRACK_Y - 6} y2={TRACK_Y + TRACK_H + 6} />
            <circle cx={x(today)} cy={TRACK_Y + TRACK_H / 2} r={7} />
          </g>
        )}
        {SCALE.map((w) => (
          <text key={w} className="preg-cal-scale" x={x(w)} y={TRACK_Y + TRACK_H + 18} textAnchor="middle">{w}</text>
        ))}
      </svg>

      <p className="preg-cal-legend">
        <span className="preg-cal-key control" /> Control realizado
        <span className="preg-cal-key today" /> Hoy{today != null && ` · ${age.weeks}+${age.remainder_days} sem`}
        {pregnancy.elective_cesarean_date && (<><span className="preg-cal-key fpc" /> FPC {formatDate(pregnancy.elective_cesarean_date)}</>)}
        <span className="preg-cal-key fpp" /> FPP {pregnancy.edd_date ? formatDate(pregnancy.edd_date) : '—'}
      </p>
    </section>
  );
}
