import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { isAxiosError } from 'axios';
import {
  cancelInvoice,
  deletePayment,
  downloadInvoicePdf,
  fetchInvoice,
  registerPayment,
  voidInvoice,
} from '@/api/invoices';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { SriStatusCard } from '@/components/invoices/SriStatusCard';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { downloadBlob } from '@/lib/download';
import { formatDate, formatMoney, t } from '@/i18n/es';
import type { PaymentInput } from '@/api/types';

const METHODS: Record<string, string> = { cash: 'Efectivo', transfer: 'Transferencia', card: 'Tarjeta', other: 'Otro' };

export function InvoiceDetailPage() {
  const { id } = useParams();
  const invoiceId = Number(id);
  const qc = useQueryClient();
  const { can } = useWorkspaceCapabilities();
  const [showPay, setShowPay] = useState(false);
  const [showVoid, setShowVoid] = useState(false);

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['invoice', invoiceId],
    queryFn: () => fetchInvoice(invoiceId),
  });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['invoice', invoiceId] });
    qc.invalidateQueries({ queryKey: ['invoices'] });
  };

  const pay = useMutation({
    mutationFn: (payload: PaymentInput) => registerPayment(invoiceId, payload),
    onSuccess: () => {
      refresh();
      setShowPay(false);
    },
  });

  const voidMut = useMutation({
    mutationFn: (reason: string) => voidInvoice(invoiceId, reason),
    onSuccess: () => {
      refresh();
      setShowVoid(false);
    },
  });

  const cancelMut = useMutation({
    mutationFn: (reason: string) => cancelInvoice(invoiceId, reason),
    onSuccess: () => {
      refresh();
      setShowVoid(false);
    },
  });

  const delPay = useMutation({
    mutationFn: (paymentId: number) => deletePayment(paymentId),
    onSuccess: refresh,
  });

  const handlePdf = async () => {
    const blob = await downloadInvoicePdf(invoiceId);
    downloadBlob(blob, `${invoice?.invoice_number ?? 'factura'}.pdf`);
  };

  if (isLoading || !invoice) return <Spinner label={t.common.loading} />;

  const open = ['issued', 'partial'].includes(invoice.status);
  const hasPayments = (invoice.payments?.length ?? 0) > 0;
  const voidable = invoice.status !== 'void' && invoice.status !== 'cancelled' && can('billing.manage');

  return (
    <>
      <div className="page-head">
        <div>
          <Link className="btn sm ghost" to="/facturas" style={{ marginBottom: 8 }}>
            <Icon name="arrow" size={14} style={{ transform: 'rotate(180deg)' }} /> {t.common.back}
          </Link>
          <h1>{invoice.invoice_number}</h1>
          <p>
            {invoice.patient?.name ?? '—'} · {formatDate(invoice.issued_at)}
            {invoice.attending_doctor ? ` · Atendió ${invoice.attending_doctor.name}` : ''}
            {invoice.attending_doctor && invoice.attending_doctor_commission_pct != null
              ? ` · comisión ${Number(invoice.attending_doctor_commission_pct)}%`
              : ''}
          </p>
        </div>
        <div className="row wrap">
          <button className="btn" onClick={handlePdf}>
            <Icon name="download" size={15} /> RIDE / PDF
          </button>
          {open && can('billing.manage') && (
            <button className="btn primary" onClick={() => setShowPay(true)}>
              <Icon name="card" size={15} /> Registrar pago
            </button>
          )}
        </div>
      </div>

      <div className="invoice-detail">
        <Card padded={false}>
          <div className="card-head">
            <h3>Detalle</h3>
            <StatusBadge status={invoice.status} label={invoice.status_label} />
          </div>
          <table className="tbl">
            <thead>
              <tr><th>Descripción</th><th className="num">Cant.</th><th className="num">P. Unit</th><th className="num">Total</th></tr>
            </thead>
            <tbody>
              {invoice.items?.map((item) => (
                <tr key={item.id}>
                  <td>{item.description}</td>
                  <td className="num mono">{item.quantity}</td>
                  <td className="num mono">{formatMoney(item.unit_price)}</td>
                  <td className="num mono">{formatMoney(item.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="card-pad stack" style={{ gap: 6 }}>
            <div className="row between"><span className="muted">Subtotal</span><span className="mono">{formatMoney(invoice.subtotal)}</span></div>
            <div className="row between"><b>Total</b><b className="mono">{formatMoney(invoice.total)}</b></div>
          </div>
        </Card>

        <aside className="stack">
          <Card>
            <div className="stack" style={{ gap: 10 }}>
              <div className="row between"><span className="muted">Total</span><b className="mono">{formatMoney(invoice.total)}</b></div>
              <div className="row between"><span className="muted">Pagado</span><b className="mono money-pos">{formatMoney(invoice.amount_paid)}</b></div>
              <div className="row between amount-due-row">
                <b>Saldo</b>
                <b className={`mono saldo-val ${Number(invoice.amount_due) > 0 ? 'money-due' : 'money-pos'}`}>
                  {formatMoney(invoice.amount_due)}
                </b>
              </div>
            </div>
          </Card>

          <SriStatusCard invoice={invoice} />

          <Card padded={false}>
            <div className="card-head">
              <h3>Pagos</h3>
              {open && can('billing.manage') && (
                <button className="btn sm" onClick={() => setShowPay(true)}>
                  <Icon name="plus" size={14} /> Registrar
                </button>
              )}
            </div>
            {(!invoice.payments || invoice.payments.length === 0) ? (
              <EmptyState icon="card" message="Sin pagos registrados." />
            ) : (
              <table className="tbl">
                <thead><tr><th>Fecha</th><th>Método</th><th className="num">Monto</th><th /></tr></thead>
                <tbody>
                  {invoice.payments.map((p) => (
                    <tr key={p.id}>
                      <td>{formatDate(p.payment_date)}</td>
                      <td>{METHODS[p.payment_method ?? ''] ?? p.payment_method}</td>
                      <td className="num mono">{formatMoney(p.amount)}</td>
                      <td className="num">
                        {can('billing.manage') && (
                          <button className="icon-btn" onClick={() => delPay.mutate(p.id)} aria-label="Eliminar pago">
                            <Icon name="trash" size={15} />
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Card>

          {voidable && (
            <Card className="danger-card">
              <b className="dc-title">Anular factura</b>
              <p className="muted" style={{ fontSize: 12.5, margin: '4px 0 12px' }}>
                {hasPayments
                  ? 'Tiene pagos registrados: se generará automáticamente una nota de crédito.'
                  : 'Sin pagos: la factura se cancelará de forma permanente.'}
              </p>
              <button className="btn danger" onClick={() => setShowVoid(true)}>
                <Icon name="x" size={15} /> Anular
              </button>
            </Card>
          )}
        </aside>
      </div>

      {showPay && (
        <PaymentModal
          maxAmount={invoice.amount_due}
          pending={pay.isPending}
          error={pay.isError ? extractError(pay.error) : null}
          onClose={() => setShowPay(false)}
          onSubmit={(payload) => pay.mutate(payload)}
        />
      )}

      {showVoid && (
        <VoidModal
          hasPayments={hasPayments}
          pending={voidMut.isPending || cancelMut.isPending}
          onClose={() => setShowVoid(false)}
          onSubmit={(reason) => (hasPayments ? voidMut.mutate(reason) : cancelMut.mutate(reason))}
        />
      )}
    </>
  );
}

function extractError(error: unknown): string {
  if (isAxiosError(error)) {
    return (error.response?.data as { message?: string })?.message ?? 'No se pudo registrar el pago.';
  }
  return 'No se pudo registrar el pago.';
}

function PaymentModal({
  maxAmount,
  pending,
  error,
  onClose,
  onSubmit,
}: {
  maxAmount: string;
  pending: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (payload: PaymentInput) => void;
}) {
  const [amount, setAmount] = useState(maxAmount);
  const [method, setMethod] = useState<PaymentInput['payment_method']>('cash');

  return (
    <Modal
      title="Registrar pago"
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>Cancelar</button>
          <button
            className="btn primary"
            disabled={pending || !amount}
            onClick={() => onSubmit({ amount, payment_method: method, payment_date: ecuadorDateInputValue() })}
          >
            {pending ? 'Guardando…' : 'Registrar'}
          </button>
        </>
      }
    >
      <div className="form-grid">
        <div>
          <label className="field-label">Monto (máx. {formatMoney(maxAmount)})</label>
          <input className="field mono" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </div>
        <div>
          <label className="field-label">Método</label>
          <select className="field" value={method} onChange={(e) => setMethod(e.target.value as PaymentInput['payment_method'])}>
            <option value="cash">Efectivo</option>
            <option value="transfer">Transferencia</option>
            <option value="card">Tarjeta</option>
            <option value="other">Otro</option>
          </select>
        </div>
        {error && <p className="field-error full">{error}</p>}
      </div>
    </Modal>
  );
}

function VoidModal({
  hasPayments,
  pending,
  onClose,
  onSubmit,
}: {
  hasPayments: boolean;
  pending: boolean;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}) {
  const [reason, setReason] = useState('');
  return (
    <Modal
      title={hasPayments ? 'Anular factura' : 'Cancelar factura'}
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>Volver</button>
          <button className="btn danger-solid" disabled={pending || !reason.trim()} onClick={() => onSubmit(reason)}>
            {pending ? 'Procesando…' : 'Confirmar'}
          </button>
        </>
      }
    >
      {hasPayments && (
        <div className="alert amber" style={{ marginBottom: 14 }}>
          <span className="ai"><Icon name="alert" size={15} /></span>
          <div>
            <b>Esta factura tiene pagos</b>
            <p>Se generará automáticamente una nota de crédito por el monto pagado.</p>
          </div>
        </div>
      )}
      <label className="field-label">Motivo</label>
      <textarea className="field" value={reason} onChange={(e) => setReason(e.target.value)} />
    </Modal>
  );
}
