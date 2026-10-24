import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { retrySri } from '@/api/invoices';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { explainSriError, SRI_LABELS, SRI_TONES } from '@/features/invoices/sriErrors';
import type { Invoice } from '@/api/types';

/**
 * What happened between issuing the invoice and the SRI's answer.
 *
 * The SRI's failures are almost always the emitter's to fix (wrong
 * establishment, expired certificate, missing fiscal data), so this does not
 * just report a state — it says what to do, and only offers actions the viewer
 * actually has permission to take.
 */
export function SriStatusCard({ invoice }: { invoice: Invoice }) {
  const { can } = useWorkspaceCapabilities();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const retry = useMutation({
    mutationFn: () => retrySri(invoice.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['invoice', invoice.id] }),
  });

  if (!invoice.sri_status) {
    return null;
  }

  const isOwner = can('settings.manage');
  const canRetry = can('billing.manage');
  const explanation = explainSriError(invoice.sri_error);

  // A pending voucher past the retry budget is stuck, not slow.
  const attemptedAt = invoice.sri_last_attempt_at ? new Date(invoice.sri_last_attempt_at).getTime() : null;
  const stale =
    invoice.sri_status === 'pending' &&
    attemptedAt !== null &&
    Date.now() - attemptedAt > invoice.sri_stale_after_seconds * 1000;

  const label = stale ? 'Tarda más de lo normal' : SRI_LABELS[invoice.sri_status] ?? invoice.sri_status;
  const tone = SRI_TONES[invoice.sri_status] ?? '';

  const lastStep =
    invoice.sri_status === 'authorized' ? 'ok' : invoice.sri_status === 'rejected' ? 'fail' : 'wait';

  const time = (iso: string | null) =>
    iso ? new Date(iso).toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' }) : '';

  return (
    <Card>
      <div className="card-head flush">
        <h3>Facturación electrónica</h3>
        <span className={`badge ${tone}`}>{label}</span>
      </div>

      <div className="timeline">
        <div className="tl-item ok">
          <div className="what">Factura emitida</div>
          <div className="when">{invoice.sri_sequence_number ?? invoice.invoice_number}</div>
        </div>

        <div className={`tl-item ${invoice.sri_last_attempt_at ? 'ok' : 'wait'}`}>
          <div className="what">Enviada al SRI</div>
          <div className="when">{time(invoice.sri_last_attempt_at)}</div>
        </div>

        <div className={`tl-item ${lastStep}`}>
          <div className="what">
            {invoice.sri_status === 'authorized'
              ? 'Autorizada por el SRI'
              : invoice.sri_status === 'rejected'
                ? 'El SRI no la autorizó'
                : 'Esperando respuesta del SRI'}
          </div>
          {invoice.sri_authorization_number && (
            <div className="when mono">{invoice.sri_authorization_number}</div>
          )}
        </div>
      </div>

      {explanation && invoice.sri_status === 'rejected' && (
        <div className="alert red">
          <span className="ai"><Icon name="alert" size={16} /></span>
          <div>
            <b>{explanation.title}</b>
            <p>{explanation.detail}</p>
            <p>
              {isOwner || explanation.fixableBy === 'nobody'
                ? explanation.action
                : 'Avisa al responsable del consultorio para que lo corrija en Ajustes.'}
            </p>
          </div>
        </div>
      )}

      {stale && (
        <div className="alert amber">
          <span className="ai"><Icon name="clock" size={16} /></span>
          <div>
            <b>Lleva demasiado tiempo sin respuesta</b>
            <p>El envío al SRI debería resolverse en unos minutos. Puedes reintentarlo.</p>
          </div>
        </div>
      )}

      {(invoice.sri_status === 'rejected' || stale) && (
        <div className="row" style={{ gap: 8, marginTop: 12 }}>
          {isOwner && explanation?.fixableBy === 'owner' && (
            <button className="btn sm" onClick={() => navigate('/ajustes')}>
              Ir a Ajustes
            </button>
          )}
          {canRetry && (
            <button className="btn sm primary" disabled={retry.isPending} onClick={() => retry.mutate()}>
              {retry.isPending ? 'Reintentando…' : 'Reintentar'}
            </button>
          )}
        </div>
      )}

      {invoice.sri_error && (
        <details className="sri-raw">
          <summary>Detalle técnico</summary>
          <pre>
            {invoice.sri_error.source === 'sri'
              ? `Código ${invoice.sri_error.code}\n${invoice.sri_error.message ?? ''}\n${invoice.sri_error.info ?? ''}`.trim()
              : `Fallo interno · ${invoice.sri_error.category}\n${invoice.sri_last_attempt_at ?? ''}`.trim()}
          </pre>
        </details>
      )}
    </Card>
  );
}
