import { useSearchParams } from 'react-router-dom';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Icon } from '@/components/Icon';
import { ClinicalReportsPanel } from './ClinicalReportsPanel';
import { DailyFinancePanel } from './DailyFinancePanel';
import { SettlementPanel } from './SettlementPanel';
import { ProfitLossPanel } from './ProfitLossPanel';
import { PanoramaPanel } from './PanoramaPanel';
import { PeriodBar, usePeriod } from './PeriodBar';

export type ReportTab = 'panorama' | 'dia' | 'liquidacion' | 'resultado' | 'vigilancia';

const TABS: {
  key: ReportTab;
  label: string;
  icon: string;
  sub: string;
  permission?: 'finance.view' | 'reports.clinical';
}[] = [
  { key: 'panorama', label: 'Panorama', icon: 'home', sub: 'Cómo va el consultorio, de un vistazo' },
  { key: 'dia', label: 'Día a día', icon: 'clock', sub: 'Cierre de caja y ritmo del período' },
  {
    key: 'liquidacion',
    label: 'Liquidación médicos',
    icon: 'users',
    sub: 'Cuánto se va a los especialistas y cuánto queda en la clínica',
  },
  {
    key: 'resultado',
    label: 'Ingresos y egresos',
    icon: 'chart',
    sub: 'Utilidad neta: ingresos, comisiones y gastos',
    permission: 'finance.view',
  },
  {
    key: 'vigilancia',
    label: 'Vigilancia de salud',
    icon: 'steth',
    sub: 'Morbilidad y reporte MSP (RDACAA)',
    permission: 'reports.clinical',
  },
];

/**
 * Reports hub. ONE period selector governs every tab, so the figures on screen
 * always reconcile with each other: the day, the settlement and the books all
 * answer for the same window. Both the tab and the period live in the URL, so a
 * reload or a shared link lands on the same report.
 */
export function ReportsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const { can } = useWorkspaceCapabilities();
  const { period, label, preset, setPeriod } = usePeriod();

  const visible = TABS.filter((tab) => !tab.permission || can(tab.permission));

  const requested = searchParams.get('tab');
  const tab: ReportTab = visible.some((t) => t.key === requested) ? (requested as ReportTab) : 'panorama';
  const active = visible.find((t) => t.key === tab);

  const selectTab = (next: ReportTab) => {
    const params = new URLSearchParams(searchParams);
    if (next === 'panorama') params.delete('tab');
    else params.set('tab', next);
    setSearchParams(params, { replace: true });
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Reportes</h1>
          <p>{active?.sub}</p>
        </div>
      </div>

      <div className="page-tabs" role="tablist" aria-label="Tipo de reporte">
        {visible.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            className={`page-tab ${tab === item.key ? 'active' : ''}`}
            onClick={() => selectTab(item.key)}
          >
            <Icon name={item.icon} size={16} /> {item.label}
          </button>
        ))}
      </div>

      <PeriodBar period={period} preset={preset} label={label} onChange={setPeriod} />

      {tab === 'panorama' && <PanoramaPanel period={period} onOpen={selectTab} />}
      {tab === 'dia' && <DailyFinancePanel period={period} onPeriodChange={setPeriod} />}
      {tab === 'liquidacion' && <SettlementPanel period={period} label={label} />}
      {tab === 'resultado' && <ProfitLossPanel period={period} label={label} />}
      {tab === 'vigilancia' && <ClinicalReportsPanel period={period} />}
    </>
  );
}
