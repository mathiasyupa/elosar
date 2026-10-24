import { useQuery } from '@tanstack/react-query';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { useAuthStore } from '@/store/auth';
import { fetchPregnancyTimeline } from '@/api/obstetrics';
import type { PregnancyTimelinePoint } from '@/api/types';

const W = 660;
const H = 220;
const PAD = { top: 14, right: 14, bottom: 28, left: 38 };

/** Semanas que se pintan siempre, aunque los controles empiecen tarde: una curva
 *  que empieza en la semana 24 engaña sobre cuándo empezó el control. */
const X_MIN = 6;
const X_MAX = 42;

interface Series {
  key: 'fundal_height' | 'weight';
  label: string;
  unit: string;
  stroke: string;
}

const SERIES: Series[] = [
  { key: 'fundal_height', label: 'Altura uterina', unit: 'cm', stroke: 'var(--accent)' },
  { key: 'weight', label: 'Peso', unit: 'kg', stroke: 'var(--violet)' },
];

function scaleX(weeks: number): number {
  const usable = W - PAD.left - PAD.right;
  return PAD.left + ((weeks - X_MIN) / (X_MAX - X_MIN)) * usable;
}

/**
 * La curva del embarazo: altura uterina y peso contra la edad gestacional.
 *
 * **Sin banda de normalidad, a propósito.** Los percentiles de altura uterina son
 * una tabla publicada (CLAP/OPS) y este producto no dibuja un rango clínico que no
 * pueda citar — la misma regla que mantiene fuera el puntaje de riesgo del MSP
 * hasta que aparezca su manual. Lo que sí muestra, que es lo que se mira en
 * consulta, es si la medida **progresa**.
 *
 * Cada serie escala sobre su propio rango: el peso ronda los 60-80 y la altura
 * uterina los 10-40, y compartir eje aplastaría una de las dos contra el borde.
 */
export function PregnancyCurve({ pregnancyId }: { pregnancyId: number }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const timeline = useQuery({
    queryKey: ['pregnancy-timeline', activeWorkspaceId, pregnancyId],
    queryFn: () => fetchPregnancyTimeline(pregnancyId),
  });

  if (timeline.isLoading) return <Spinner label="Cargando la curva…" />;

  const points = timeline.data ?? [];
  const measured = SERIES.filter((s) => points.some((p) => p[s.key] != null));

  if (measured.length === 0) {
    return (
      <EmptyState
        icon="chart"
        message="Todavía no hay medidas que graficar."
        hint="La curva se dibuja sola con la altura uterina y el peso de cada control prenatal."
      />
    );
  }

  const gridWeeks = [10, 15, 20, 25, 30, 35, 40];

  return (
    <div className="chart-scroll preg-curve">
      <svg
        viewBox={`0 0 ${W} ${H}`}
        width="100%"
        height={H}
        role="img"
        aria-label="Altura uterina y peso por semana de gestación"
      >
        {gridWeeks.map((w) => (
          <g key={w}>
            <line
              x1={scaleX(w)}
              y1={PAD.top}
              x2={scaleX(w)}
              y2={H - PAD.bottom}
              stroke="var(--divider)"
              strokeWidth="1"
            />
            <text
              x={scaleX(w)}
              y={H - 9}
              textAnchor="middle"
              fontSize="10.5"
              fill="var(--text-3)"
            >
              {w}
            </text>
          </g>
        ))}
        <text x={PAD.left} y={H - 9} fontSize="10.5" fill="var(--text-3)">
          sem
        </text>

        {measured.map((s) => {
          const vals = points
            .filter((p): p is PregnancyTimelinePoint & { weeks: number } => p.weeks != null && p[s.key] != null)
            .map((p) => ({ weeks: p.weeks, v: p[s.key] as number }));

          // Rango propio con un margen, para que la línea no toque los bordes.
          const min = Math.min(...vals.map((v) => v.v));
          const max = Math.max(...vals.map((v) => v.v));
          const span = max - min || 1;
          const y = (v: number) =>
            H - PAD.bottom - ((v - min + span * 0.15) / (span * 1.3)) * (H - PAD.top - PAD.bottom);

          const path = vals.map((p) => `${scaleX(p.weeks)},${y(p.v)}`).join(' ');

          return (
            <g key={s.key}>
              {vals.length > 1 && (
                <polyline points={path} fill="none" stroke={s.stroke} strokeWidth="2.5" />
              )}
              {vals.map((p) => (
                <circle
                  key={`${s.key}-${p.weeks}`}
                  cx={scaleX(p.weeks)}
                  cy={y(p.v)}
                  r="4"
                  fill={s.stroke}
                >
                  <title>{`${s.label}: ${p.v} ${s.unit} · semana ${p.weeks}`}</title>
                </circle>
              ))}
            </g>
          );
        })}
      </svg>

      <div className="chart-legend">
        {measured.map((s) => (
          <span key={s.key}>
            <i style={{ background: s.stroke }} /> {s.label} ({s.unit})
          </span>
        ))}
      </div>
    </div>
  );
}
