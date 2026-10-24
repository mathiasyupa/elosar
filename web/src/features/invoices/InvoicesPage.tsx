import { useEffect, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { fetchInvoice, fetchInvoices, downloadInvoicePdf } from '@/api/invoices';
import { fetchBillables } from '@/api/billing';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { NewInvoiceModal } from './NewInvoiceModal';
import { CASHIER_PENDING_COPY } from './billingFlowCopy';
import { SriPanel } from './SriPanel';
import { SRI_LABELS } from './sriErrors';
import { downloadBlob } from '@/lib/download';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate, formatMoney, formatTime, t } from '@/i18n/es';
import type { Billable, Invoice, Patient } from '@/api/types';
import { QueryError } from '@/components/ui/QueryError';

type InvoicesTab = 'facturas' | 'sri';

const TABS: { key: InvoicesTab; label: string; icon: string; sub: string }[] = [
  { key: 'facturas', label: 'Facturas', icon: 'invoice', sub: 'Comprobantes del workspace' },
  { key: 'sri', label: 'SRI', icon: 'shield', sub: 'Estado de tus comprobantes ante el SRI' },
];

const FILTERS: { id: string; label: string; value?: string }[] = [
  { id: 'all', label: 'Todas' },
  { id: 'issued', label: 'Emitidas', value: 'issued' },
  { id: 'paid', label: 'Pagadas', value: 'paid' },
  { id: 'partial', label: 'Parciales', value: 'partial' },
  { id: 'void', label: 'Anuladas', value: 'void' },
];

function InvoicePreview({ invoice }: { invoice: Invoice }) {
  const navigate = useNavigate();
  const toast = useUiStore((s) => s.toast);
  const { data } = useQuery({ queryKey: ['invoice', invoice.id], queryFn: () => fetchInvoice(invoice.id) });
  const inv = data ?? invoice;
  const open = ['issued', 'partial'].includes(inv.status);

  const handlePdf = async () => {
    try {
      const blob = await downloadInvoicePdf(inv.id);
      downloadBlob(blob, `${inv.invoice_number ?? 'factura'}.pdf`);
    } catch {
      toast('No se pudo generar el PDF.', 'error');
    }
  };

  return (
    <div className="preview-card">
      <div className="pp-head">
        <span className="avatar pp-avatar" style={{ background: avatarColor(inv.patient?.id ?? inv.id) }}>
          {initials(inv.patient?.name ?? '—')}
        </span>
        <div style={{ flex: 1 }}>
          <b>{inv.patient?.name ?? '—'}</b>
          <span className="muted pp-id"><span className="mono">CI {inv.patient?.id_number ?? '—'}</span></span>
        </div>
      </div>

      <div className="row between" style={{ marginBottom: 10 }}>
        <span className="mono" style={{ fontWeight: 700 }}>{inv.invoice_number ?? '—'}</span>
        <StatusBadge status={inv.status} label={inv.status_label} />
      </div>

      <div className="invoice-amounts">
        <div><span>Total</span><b className="mono">{formatMoney(inv.total)}</b></div>
        <div><span>Pagado</span><b className="mono money-pos">{formatMoney(inv.amount_paid)}</b></div>
        <div><span>Saldo</span><b className={`mono ${Number(inv.amount_due) > 0 ? 'money-due' : 'money-pos'}`}>{formatMoney(inv.amount_due)}</b></div>
      </div>

      {inv.items && inv.items.length > 0 && (
        <div className="preview-list">
          <div className="pl-label">Ítems</div>
          {inv.items.slice(0, 4).map((it) => (
            <div key={it.id} className="row between" style={{ fontSize: 12.5 }}>
              <span className="muted ellipsis">{it.description}</span>
              <span className="mono">{formatMoney(it.line_total)}</span>
            </div>
          ))}
        </div>
      )}

      {inv.payments && inv.payments.length > 0 && (
        <div className="preview-list">
          <div className="pl-label">Pagos</div>
          {inv.payments.map((p) => (
            <div key={p.id} className="row between" style={{ fontSize: 12.5 }}>
              <span className="muted">{formatDate(p.payment_date)}</span>
              <span className="mono">{formatMoney(p.amount)}</span>
            </div>
          ))}
        </div>
      )}

      <div className="row between" style={{ fontSize: 12, margin: '4px 0 12px' }}>
        <span className="muted">SRI</span>
        {inv.sri_status ? (
          <StatusBadge status={inv.sri_status} label={SRI_LABELS[inv.sri_status] ?? inv.sri_status} />
        ) : (
          <span className="muted">—</span>
        )}
      </div>

      <div className="pp-actions" style={{ gridTemplateColumns: '1fr 1fr' }}>
        <button className="pp-action" onClick={handlePdf}><Icon name="download" size={15} /> PDF</button>
        {open && (
          <button className="pp-action" onClick={() => navigate(`/facturas/${inv.id}`)}>
            <Icon name="card" size={15} /> Registrar pago
          </button>
        )}
      </div>
      <button className="btn primary" style={{ width: '100%', marginTop: 10 }} onClick={() => navigate(`/facturas/${inv.id}`)}>
        Abrir factura
      </button>
    </div>
  );
}

export function InvoicesPage() {
  const [filter, setFilter] = useState('all');
  const [showNew, setShowNew] = useState(false);
  const [charging, setCharging] = useState<Billable | null>(null);
  const [selected, setSelected] = useState<Invoice | null>(null);
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const qc = useQueryClient();
  const { isClinic, can } = useWorkspaceCapabilities();
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId);
  const status = FILTERS.find((f) => f.id === filter)?.value;

  // The tab lives in the URL, so a reload or a shared link lands on the same
  // one — same contract as Reportes.
  const tab: InvoicesTab = searchParams.get('tab') === 'sri' ? 'sri' : 'facturas';

  const selectTab = (next: InvoicesTab) => {
    const params = new URLSearchParams(searchParams);
    if (next === 'facturas') params.delete('tab');
    else params.set('tab', next);
    setSearchParams(params, { replace: true });
  };

  // "Nueva factura" launched from Seguros passes a patient through router state.
  const prefillPatient = (location.state as { newInvoiceFor?: Patient } | null)?.newInvoiceFor ?? null;
  useEffect(() => {
    if (prefillPatient) setShowNew(true);
  }, [prefillPatient]);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['invoices', activeWorkspaceId, status ?? 'all'],
    queryFn: () => fetchInvoices(1, status),
    placeholderData: keepPreviousData,
  });

  /**
   * "Por cobrar": citas completadas que aún no tienen factura (clínica + puede facturar).
   *
   * Esta lista es un traspaso ENTRE PERSONAS: la llena el médico al cerrar la
   * consulta y la vacía caja al cobrar. La invalidación de caché solo alcanza al
   * navegador que hizo el cambio, así que sin sondeo la cajera se quedaba mirando
   * una lista vieja hasta recargar a mano. 30 s es el mismo intervalo que ya usa
   * la sala de espera, que es exactamente el mismo tipo de traspaso.
   *
   * React Query pausa el sondeo cuando la pestaña está oculta, así que esto no
   * consulta en segundo plano ni gasta batería en un teléfono guardado.
   */
  const { data: billables } = useQuery({
    queryKey: ['billables', activeWorkspaceId],
    queryFn: fetchBillables,
    enabled: isClinic && can('billing.view'),
    refetchInterval: 30_000,
  });

  const invoices = data?.data ?? [];
  // The server only sends doctor_share to the employed doctor whose attentions
  // these are; when present we show what they actually earn next to the total.
  const showDoctorShare = invoices.some((i) => i.doctor_share != null);

  useEffect(() => {
    if (invoices.length && (!selected || !invoices.some((i) => i.id === selected.id))) {
      setSelected(invoices[0]!);
    }
  }, [invoices, selected]);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{t.invoices.title}</h1>
          <p>
            {tab === 'facturas' && data?.meta.total != null
              ? `${data.meta.total} comprobante(s)`
              : TABS.find((item) => item.key === tab)?.sub}
          </p>
        </div>
        {can('billing.manage') && (
          <button className="btn primary" onClick={() => setShowNew(true)}>
            <Icon name="plus" size={16} strokeWidth={2.2} /> Nueva factura
          </button>
        )}
      </div>

      <div className="page-tabs" role="tablist" aria-label="Vista de facturación">
        {TABS.map((item) => (
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

      {tab === 'sri' && <SriPanel />}

      {tab === 'facturas' && billables && billables.length > 0 && (
        <Card padded={false} className="invoice-pending">
          <div className="card-head">
            <div>
              <h3>{CASHIER_PENDING_COPY.heading}</h3>
              <p className="muted">{CASHIER_PENDING_COPY.nextStep}</p>
            </div>
            <span className="sub">{billables.length} pendiente(s)</span>
          </div>
          <table className="tbl tbl-cards">
            <thead>
              <tr><th>Hora</th><th>Paciente</th><th>Médico</th><th className="num"></th></tr>
            </thead>
            <tbody>
              {billables.map((b) => (
                <tr
                  key={b.appointment_id}
                  className="row-link"
                  onClick={(e) => {
                    if ((e.target as HTMLElement).closest('button, a, input, select')) return;
                    if (can('billing.manage')) setCharging(b);
                  }}
                >
                  <td className="mono" data-label="Hora">{formatTime(b.date)}</td>
                  <td data-label="Paciente">
                    <div className="who">
                      <span className="avatar-sm" style={{ background: avatarColor(b.patient?.id ?? b.appointment_id) }}>
                        {initials(b.patient?.name ?? '—')}
                      </span>
                      <div style={{ minWidth: 0 }}>
                        <b>{b.patient?.name ?? '—'}</b>
                        <span className="muted" style={{ display: 'block', fontSize: 12 }}>
                          <span className="mono">CI {b.patient?.id_number ?? '—'}</span>
                        </span>
                      </div>
                    </div>
                  </td>
                  <td className="muted" data-label="Médico">{b.doctor?.name ?? 'Sin asignar'}</td>
                  <td className="num" data-label="">
                    {can('billing.manage') ? (
                      <button className="btn sm primary" onClick={() => setCharging(b)}>
                        <Icon name="invoice" size={14} /> {CASHIER_PENDING_COPY.action}
                      </button>
                    ) : (
                      <span className="badge amber plain">Pendiente de factura</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {tab === 'facturas' && (
        <div className="split-view invoice-workspace">
          <Card padded={false}>
            <div className="card-head">
              <div className="pill-row">
                {FILTERS.map((f) => (
                  <button key={f.id} className={`pill ${filter === f.id ? 'active' : ''}`} onClick={() => setFilter(f.id)}>
                    {f.label}
                  </button>
                ))}
              </div>
            </div>

            {isLoading && <div className="card-pad"><Spinner label={t.common.loading} /></div>}
            {(isError || isPaused) && (
              <div className="card-pad">
                <QueryError
                  error={error}
                  area="las facturas"
                  onRetry={() => void refetch()}
                  retrying={isFetching} paused={isPaused}
                />
              </div>
            )}
            {data && invoices.length === 0 && (
              <EmptyState
                icon="invoice"
                message={filter === 'all' ? 'Aún no hay facturas.' : 'No hay facturas con este estado.'}
                hint={filter === 'all' ? 'Crea la primera factura para empezar a cobrar.' : undefined}
                action={
                  filter === 'all' && can('billing.manage') ? (
                    <button className="btn primary sm" onClick={() => setShowNew(true)}>
                      <Icon name="plus" size={14} /> Nueva factura
                    </button>
                  ) : undefined
                }
              />
            )}

            {invoices.length > 0 && (
              /* tbl-cards: below 720px each invoice restacks as a labelled card
                 (9 columns are unreadable on a phone). data-label on every cell
                 supplies the heading the hidden thead used to provide. */
              <div className="table-scroll" role="region" aria-label="Listado de facturas" tabIndex={0}>
                <table className="tbl tbl-cards">
                  <thead>
                    <tr>
                      <th>{t.invoices.date}</th>
                      <th>{t.invoices.number}</th>
                      <th>{t.invoices.patient}</th>
                      <th>Médico</th>
                      <th className="num">{t.invoices.total}</th>
                      {showDoctorShare && (
                        <th className="num" title="Lo que te corresponde de lo ya cobrado">Tu parte</th>
                      )}
                      <th className="num">{t.invoices.due}</th>
                      <th>{t.invoices.status}</th>
                      <th className="num" aria-label="Abrir" />
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((invoice) => (
                      <tr
                        key={invoice.id}
                        className={`row-link ${selected?.id === invoice.id ? 'selected' : ''}`.trim()}
                        onClick={() => setSelected(invoice)}
                        onDoubleClick={() => navigate(`/facturas/${invoice.id}`)}
                      >
                        <td className="mono muted" data-label={t.invoices.date}>{formatDate(invoice.issued_at)}</td>
                        <td className="mono" data-label={t.invoices.number}>{invoice.invoice_number ?? '—'}</td>
                        <td data-label={t.invoices.patient}>{invoice.patient?.name ?? '—'}</td>
                        <td className="muted" data-label="Médico">{invoice.attending_doctor?.name ?? '—'}</td>
                        <td className="num mono" data-label={t.invoices.total}>{formatMoney(invoice.total)}</td>
                        {showDoctorShare && (
                          <td className="num mono money-accent" data-label="Tu parte">
                            {formatMoney(invoice.doctor_share ?? '0')}
                          </td>
                        )}
                        <td className={`num mono ${Number(invoice.amount_due) > 0 ? 'money-due' : ''}`} data-label={t.invoices.due}>
                          {formatMoney(invoice.amount_due)}
                        </td>
                        <td data-label={t.invoices.status}><StatusBadge status={invoice.status} label={invoice.status_label} /></td>
                        <td className="num" data-label="">
                          <button
                            className="icon-btn row-open"
                            aria-label={`Abrir factura ${invoice.invoice_number ?? invoice.id}`}
                            onClick={(e) => {
                              e.stopPropagation();
                              navigate(`/facturas/${invoice.id}`);
                            }}
                          >
                            <Icon name="arrow" size={15} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <aside className="split-aside">
            {selected ? (
              <InvoicePreview invoice={selected} />
            ) : (
              <Card><p className="muted" style={{ fontSize: 13 }}>Selecciona una factura para ver el detalle.</p></Card>
            )}
          </aside>
        </div>
      )}

      {showNew && (
        <NewInvoiceModal
          initialPatient={prefillPatient}
          onClose={() => {
            setShowNew(false);
            if (prefillPatient) navigate('/facturas', { replace: true, state: null });
          }}
          onCreated={(invoice) => {
            setShowNew(false);
            navigate(`/facturas/${invoice.id}`);
          }}
        />
      )}

      {charging && (
        <NewInvoiceModal
          initialPatient={charging.patient}
          initialServices={charging.services}
          appointmentId={charging.appointment_id}
          attendingDoctorId={charging.doctor?.id ?? null}
          attendingDoctorName={charging.doctor?.name ?? null}
          onClose={() => setCharging(null)}
          onCreated={(invoice) => {
            setCharging(null);
            qc.invalidateQueries({ queryKey: ['billables'] });
            qc.invalidateQueries({ queryKey: ['invoices'] });
            navigate(`/facturas/${invoice.id}`);
          }}
        />
      )}
    </>
  );
}
