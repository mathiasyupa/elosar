import { Card } from './Card';
import { Icon } from '@/components/Icon';

type KpiTone = 'accent' | 'green' | 'amber' | 'blue' | 'violet' | 'red';

/** Compact single-row KPI for dashboards: icon chip + label + value + sub.
 *  Use the large `Kpi` only when the number IS the page content (reports). */
export function KpiMini({
  icon,
  label,
  value,
  tone = 'accent',
  sub,
}: {
  icon: string;
  label: string;
  value: string;
  tone?: KpiTone;
  sub?: string;
}) {
  return (
    <Card padded={false} className={`kpi-mini tone-${tone}`}>
      <span className="km-ic">
        <Icon name={icon} size={17} />
      </span>
      <div className="km-body">
        <span className="km-label">{label}</span>
        <b className="km-val">{value}</b>
        {sub && <span className="km-sub">{sub}</span>}
      </div>
    </Card>
  );
}

interface KpiProps {
  icon: string;
  label: string;
  value: string;
  /** Semantic tone of the icon chip — tokens only, never a raw color. */
  tone?: KpiTone;
  delta?: { dir: 'up' | 'down'; text: string };
}

/** Large KPI tile for report pages (the number IS the content). Same soft-chip
 *  language as KpiMini so every metric in the app speaks one tone system. */
export function Kpi({ icon, label, value, tone = 'accent', delta }: KpiProps) {
  return (
    <Card padded={false} className={`kpi tone-${tone}`}>
      <div className="top">
        <div className="ic">
          <Icon name={icon} size={22} />
        </div>
      </div>
      <div className="label">{label}</div>
      <div className="val">{value}</div>
      {delta && (
        <div className={`delta ${delta.dir}`}>
          <Icon name="arrow" size={13} /> {delta.text}
        </div>
      )}
    </Card>
  );
}
