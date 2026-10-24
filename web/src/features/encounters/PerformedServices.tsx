import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchMedicalServices } from '@/api/catalogs';
import { useAuthStore } from '@/store/auth';
import { Icon } from '@/components/Icon';
import { formatMoney } from '@/i18n/es';
import { normalizeText } from '@/lib/string';
import type { RecordServiceInput } from '@/api/types';

/** Spanish labels for the catalogue categories (English in the schema). */
const CATEGORY_LABELS: Record<string, string> = {
  general: 'General',
  laboratory: 'Laboratorio',
  imaging: 'Imagenología',
  procedure: 'Procedimiento',
  dental: 'Odontología',
};

interface Props {
  services: RecordServiceInput[];
  onChange: (services: RecordServiceInput[]) => void;
  /** Mirrors `billing.manage`. False = declare only; the cash desk prices it. */
  showPrices: boolean;
}

/**
 * What the doctor performed during the visit, so the invoice is not rebuilt
 * from memory at the cash desk.
 *
 * Prices are shown only to someone who may set them. In a solo consultorio that
 * is the same person who charges; in a clinic the doctor declares WHAT was done
 * and caja decides what it costs. The server drops the price either way, so
 * this flag is presentation, not the boundary.
 */
export function PerformedServices({ services, onChange, showPrices }: Props) {
  const [query, setQuery] = useState('');
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const { data: catalog } = useQuery({
    queryKey: ['medical-services', activeWorkspaceId],
    queryFn: () => fetchMedicalServices(),
  });

  const normalizedQuery = normalizeText(query);

  const matches = useMemo(() => {
    const active = (catalog ?? []).filter((s) => s.is_active);
    if (!normalizedQuery) return active.slice(0, 8);
    return active
      .filter((s) => normalizeText(`${s.name} ${CATEGORY_LABELS[s.category ?? ''] ?? ''}`).includes(normalizedQuery))
      .slice(0, 8);
  }, [catalog, normalizedQuery]);

  const add = (service: (typeof matches)[number]) => {
    onChange([
      ...services,
      {
        medical_service_id: service.id,
        name: service.name,
        category: service.category,
        quantity: 1,
        // Sent regardless; the server keeps it only for billing.manage.
        unit_price: service.base_price,
      },
    ]);
    setQuery('');
  };

  const addFreeText = () => {
    const name = query.trim();
    if (!name) return;
    onChange([...services, { medical_service_id: null, name, category: null, quantity: 1, unit_price: null }]);
    setQuery('');
  };

  const update = (index: number, patch: Partial<RecordServiceInput>) => {
    onChange(services.map((s, i) => (i === index ? { ...s, ...patch } : s)));
  };

  const remove = (index: number) => onChange(services.filter((_, i) => i !== index));

  // Display only — the invoice total is computed server-side with bcmath.
  const total = services.reduce((sum, s) => {
    const price = Number.parseFloat(s.unit_price ?? '');
    const qty = Number(s.quantity ?? 1);
    return Number.isFinite(price) ? sum + price * qty : sum;
  }, 0);

  return (
    <div className="performed-services">
      <div className="search" style={{ maxWidth: 420 }}>
        <Icon name="search" />
        <input
          placeholder="Buscar en aranceles: consulta, laboratorio, curación…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label="Buscar un servicio realizado en el catálogo de aranceles"
        />
      </div>

      {query.trim() && (
        <div className="ps-suggestions">
          {matches.map((s) => (
            <button key={s.id} type="button" className="ps-suggestion" onClick={() => add(s)}>
              <span>
                <b>{s.name}</b>
                {s.category && <span className="muted"> · {CATEGORY_LABELS[s.category] ?? s.category}</span>}
              </span>
              {showPrices && <span className="mono">{formatMoney(s.base_price)}</span>}
            </button>
          ))}
          <button type="button" className="ps-suggestion ps-freetext" onClick={addFreeText}>
            <span>
              <Icon name="plus" size={14} /> Añadir «{query.trim()}» sin arancel
            </span>
            <span className="muted">No entra en el desglose por categoría</span>
          </button>
        </div>
      )}

      {services.length === 0 ? (
        <p className="muted ps-empty">
          Sin servicios declarados. Lo que registres aquí es lo que caja verá para facturar.
        </p>
      ) : (
        <table className="tbl ps-table">
          <thead>
            <tr>
              <th>Servicio</th>
              <th style={{ width: 90 }}>Cantidad</th>
              {showPrices && <th style={{ width: 120 }}>Precio unit.</th>}
              {showPrices && <th style={{ width: 100 }}>Total</th>}
              <th style={{ width: 44 }}><span className="sr-only">Quitar</span></th>
            </tr>
          </thead>
          <tbody>
            {services.map((s, i) => {
              const price = Number.parseFloat(s.unit_price ?? '');
              const lineTotal = Number.isFinite(price) ? price * Number(s.quantity ?? 1) : null;
              return (
                <tr key={`${s.name}-${i}`}>
                  <td>
                    <b>{s.name}</b>
                    {s.category && (
                      <span className="muted" style={{ display: 'block', fontSize: 11.5 }}>
                        {CATEGORY_LABELS[s.category] ?? s.category}
                      </span>
                    )}
                  </td>
                  <td>
                    <input
                      className="field mono"
                      type="number"
                      min="0.01"
                      step="0.01"
                      value={s.quantity ?? 1}
                      aria-label={`Cantidad de ${s.name}`}
                      onChange={(e) => update(i, { quantity: Number(e.target.value) })}
                    />
                  </td>
                  {showPrices && (
                    <td>
                      <input
                        className="field mono"
                        type="number"
                        min="0"
                        step="0.01"
                        value={s.unit_price ?? ''}
                        aria-label={`Precio unitario de ${s.name}`}
                        onChange={(e) => update(i, { unit_price: e.target.value || null })}
                      />
                    </td>
                  )}
                  {showPrices && (
                    <td className="mono">{lineTotal === null ? '—' : formatMoney(String(lineTotal))}</td>
                  )}
                  <td>
                    <button
                      type="button"
                      className="icon-btn"
                      aria-label={`Quitar ${s.name}`}
                      onClick={() => remove(i)}
                    >
                      <Icon name="x" size={14} />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}

      {showPrices && services.length > 0 && (
        <p className="ps-total">
          Total estimado: <strong className="mono">{formatMoney(String(total))}</strong>
          <span className="muted"> · el monto definitivo lo calcula la factura, con IVA</span>
        </p>
      )}

      {!showPrices && services.length > 0 && (
        <p className="muted ps-note">
          <Icon name="alert" size={14} /> Caja pone los precios y emite la factura.
        </p>
      )}
    </div>
  );
}
