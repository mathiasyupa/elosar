import { useState } from 'react';
import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { fetchInvoices, fetchSriSummary, retrySri } from '@/api/invoices';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { KpiMini } from '@/components/ui/Kpi';
import { Spinner } from '@/components/ui/Spinner';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { Icon } from '@/components/Icon';
import { explainSriError, SRI_LABELS } from './sriErrors';
import { formatDate, formatMoney, t } from '@/i18n/es';
import type { Invoice } from '@/api/types';

/** The `sri_status` values the server understands, first one being the default:
 *  on arrival the only question worth asking is what is still unfinished. */
const FILTERS: { value: string; label: string }[] = [
  { value: 'needs_attention', label: 'Necesitan atención' },
  { value: 'pending', label: 'En cola' },
  { value: 'rejected', label: 'Rechazadas' },
  { value: 'authorized', label: 'Autorizadas' },
  { value: 'not_sent', label: 'Sin enviar' },
];

/**
 * The SRI control panel inside Facturación: how many vouchers are outstanding,
 * which ones, why, and the one action that resolves them.
 *
 * Both queries poll, because the authorization job moves vouchers on its own in
 * the background: a queued comprobante becomes authorized with nobody touching
 * the screen, and a static count would quietly go stale while the user is
 * looking straight at it. Same 30 s as the "Por cobrar" handover next door.
 */
export function SriPanel() {
  const [sriStatus, setSriStatus] = useState(FILTERS[0]!.value);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { can } = useWorkspaceCapabilities();

  const summary = useQuery({
    queryKey: ['sri-summary', activeWorkspaceId],
    queryFn: fetchSriSummary,
    refetchInterval: 30_000,
  });

  // Paged, not capped: "Necesitan atención" is precisely the list meant to be
  // emptied, and stopping at 20 with no sign of the rest hides the work. The
  // poll refreshes every page already loaded, which is what we want here.
  const { data, isLoading, isError, isPaused, error, refetch, isFetching, fetchNextPage, hasNextPage, isFetchingNextPage } =
    useInfiniteQuery({
      queryKey: ['invoices', activeWorkspaceId, 'sri', sriStatus],
      queryFn: ({ pageParam }) => fetchInvoices(pageParam, undefined, sriStatus),
      initialPageParam: 1,
      getNextPageParam: (last) =>
        last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
      placeholderData: keepPreviousData,
      refetchInterval: 30_000,
    });

  const invoices = data?.pages.flatMap((page) => page.data) ?? [];
  const total = data?.pages[0]?.meta.total ?? 0;

  return (
    <>
      <div className="dashboard-kpi-strip">
        <KpiMini
          icon="clock"
          label="En cola"
          value={String(summary.data?.pending ?? 0)}
          tone="amber"
          sub="Esperando respuesta del SRI"
        />
        <KpiMini
          icon="alert"
          label="Rechazadas"
          value={String(summary.data?.rejected ?? 0)}
          tone="red"
          sub="El SRI no las autorizó"
        />
        {/* Bounded to the month on purpose. It is the tile that says "this
            works" — worth keeping — but a lifetime total would grow into the
            biggest number on screen and the only one that never asks for
            anything, which trains the eye to skip the whole strip. */}
        <KpiMini
          icon="check"
          label="Autorizadas este mes"
          value={String(summary.data?.authorized_this_month ?? 0)}
          tone="green"
          sub="Con validez fiscal"
        />
        {/* Only worth a tile when there are any: a workspace with e-invoicing
            on from day one should not carry a permanent zero. */}
        {summary.data != null && summary.data.not_sent > 0 && (
          <KpiMini
            icon="file"
            label="Sin enviar"
            value={String(summary.data.not_sent)}
            sub="Documentos internos"
          />
        )}
      </div>

      <Card padded={false} style={{ marginTop: 18 }}>
        <div className="card-head">
          <div className="pill-row">
            {FILTERS.map((f) => (
              <button
                key={f.value}
                className={`pill ${sriStatus === f.value ? 'active' : ''}`}
                onClick={() => setSriStatus(f.value)}
                type="button"
              >
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
              area="el estado del SRI"
              onRetry={() => void refetch()}
              retrying={isFetching}
              paused={isPaused}
            />
          </div>
        )}

        {data && invoices.length === 0 && (
          <EmptyState
            icon={sriStatus === 'needs_attention' ? 'check' : 'invoice'}
            message={
              sriStatus === 'needs_attention'
                ? 'No tienes nada pendiente con el SRI.'
                : 'No hay comprobantes en este estado.'
            }
            hint={
              sriStatus === 'needs_attention'
                ? 'Todo lo emitido está autorizado o no requería envío.'
                : undefined
            }
          />
        )}

        {invoices.length > 0 && (
          <div className="table-scroll" role="region" aria-label="Comprobantes ante el SRI" tabIndex={0}>
            <table className="tbl tbl-cards">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Número</th>
                  <th>Paciente</th>
                  <th className="num">Total</th>
                  <th>Estado</th>
                  <th>Motivo</th>
                  <th className="num" aria-label="Acciones" />
                </tr>
              </thead>
              <tbody>
                {invoices.map((invoice) => (
                  <SriRow key={invoice.id} invoice={invoice} canRetry={can('billing.manage')} />
                ))}
              </tbody>
            </table>
          </div>
        )}

        {invoices.length > 0 && (
          <div className="list-more">
            <span className="muted">{invoices.length} de {total}</span>
            {hasNextPage && (
              <button className="btn sm" type="button" disabled={isFetchingNextPage} onClick={() => void fetchNextPage()}>
                {isFetchingNextPage ? 'Cargando…' : 'Cargar más'}
              </button>
            )}
          </div>
        )}
      </Card>
    </>
  );
}

/**
 * One voucher, with the SRI's answer already translated.
 *
 * The retry is the same call the detail page makes, and the server refuses it
 * for two reasons: an already-authorized comprobante (so a double click cannot
 * file the same one twice) and an annulled invoice (filing it would present the
 * tax authority with a document this workspace considers void). The second case
 * reaches this table — the explicit state filters list annulled invoices on
 * purpose — so the row shows why instead of offering a button that always fails.
 */
function SriRow({ invoice, canRetry }: { invoice: Invoice; canRetry: boolean }) {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const toast = useUiStore((s) => s.toast);
  const explanation = explainSriError(invoice.sri_error);

  const retry = useMutation({
    mutationFn: () => retrySri(invoice.id),
    onSuccess: () => {
      // The row, the lists it appears in and the counters above all move at
      // once — otherwise the badge keeps claiming a rejection that just left.
      qc.invalidateQueries({ queryKey: ['invoice', invoice.id] });
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['sri-summary'] });
      toast('Comprobante reenviado al SRI.', 'success');
    },
    onError: () => toast('No se pudo reenviar el comprobante.', 'error'),
  });

  const annulled = invoice.status === 'void' || invoice.status === 'cancelled';
  const retryable = !annulled && (invoice.sri_status === 'rejected' || invoice.sri_status === 'pending');

  return (
    <tr>
      <td className="mono muted" data-label="Fecha">{formatDate(invoice.issued_at)}</td>
      <td className="mono" data-label="Número">{invoice.invoice_number ?? '—'}</td>
      <td data-label="Paciente">{invoice.patient?.name ?? '—'}</td>
      <td className="num mono" data-label="Total">{formatMoney(invoice.total)}</td>
      <td data-label="Estado">
        <div className="row wrap" style={{ gap: 5 }}>
          <StatusBadge
            status={invoice.sri_status ?? 'not_sent'}
            label={invoice.sri_status ? SRI_LABELS[invoice.sri_status] ?? invoice.sri_status : 'Sin enviar'}
          />
          {/* Without this the row reads as ordinary outstanding work, and the
              missing "Reintentar" looks like a bug rather than the reason. */}
          {annulled && <StatusBadge status={invoice.status} label={invoice.status_label} />}
        </div>
      </td>
      <td data-label="Motivo">
        {explanation ? (
          <>
            <b className="sri-reason">{explanation.title}</b>
            {/* What the SRI said is still worth knowing, but its "go fix this in
                Ajustes" is advice for a document nobody will send again. */}
            <span className="muted sri-reason-detail">
              {annulled ? 'La factura se anuló; ya no se enviará al SRI.' : explanation.action}
            </span>
          </>
        ) : (
          <span className="muted">
            {annulled
              ? 'La factura se anuló; ya no se enviará al SRI.'
              : invoice.sri_status === 'pending'
                ? 'Esperando respuesta del SRI.'
                : '—'}
          </span>
        )}
      </td>
      <td className="num" data-label="">
        <div className="row" style={{ gap: 6, justifyContent: 'flex-end' }}>
          {canRetry && retryable && (
            <button className="btn sm primary" type="button" disabled={retry.isPending} onClick={() => retry.mutate()}>
              <Icon name="refresh" size={14} />
              {retry.isPending ? 'Reintentando…' : 'Reintentar'}
            </button>
          )}
          <button
            className="icon-btn"
            type="button"
            aria-label={`Abrir factura ${invoice.invoice_number ?? invoice.id}`}
            onClick={() => navigate(`/facturas/${invoice.id}`)}
          >
            <Icon name="arrow" size={15} />
          </button>
        </div>
      </td>
    </tr>
  );
}
