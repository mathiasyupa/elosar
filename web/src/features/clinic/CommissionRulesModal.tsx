import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { fetchCommissionRules, setCommissionRules } from '@/api/clinic';
import { fetchMedicalServices } from '@/api/catalogs';
import { useUiStore } from '@/store/ui';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';

interface DraftRule {
  medical_service_id: number;
  rule_type: 'percentage' | 'fixed';
  value: string;
}

/** Arancel categories (English in the schema, Spanish on screen). */
const CATEGORY_LABELS: Record<string, string> = {
  general: 'General',
  laboratory: 'Laboratorio',
  imaging: 'Imagenología',
  procedure: 'Procedimientos',
  dental: 'Odontología',
};

interface Props {
  memberId: number;
  memberName: string;
  onSaved: () => void;
  onClose: () => void;
}

/**
 * Owner editor for a doctor's per-service commission (percentage or fixed
 * amount). Replaces the whole rule set on save. Services without a rule fall
 * back to the doctor's global % (managed in the Team table).
 */
export function CommissionRulesModal({ memberId, memberName, onSaved, onClose }: Props) {
  const toast = useUiStore((s) => s.toast);
  const [rules, setRules] = useState<DraftRule[]>([]);
  const [newServiceId, setNewServiceId] = useState<number | ''>('');
  const [newType, setNewType] = useState<'percentage' | 'fixed'>('percentage');
  const [newValue, setNewValue] = useState('');
  const [bulkCategory, setBulkCategory] = useState('');
  const [bulkType, setBulkType] = useState<'percentage' | 'fixed'>('percentage');
  const [bulkValue, setBulkValue] = useState('');

  const rulesQuery = useQuery({ queryKey: ['commission-rules', memberId], queryFn: () => fetchCommissionRules(memberId) });
  const servicesQuery = useQuery({ queryKey: ['medical-services'], queryFn: () => fetchMedicalServices() });

  useEffect(() => {
    if (rulesQuery.data) {
      setRules(
        rulesQuery.data.map((r) => ({
          medical_service_id: r.medical_service_id,
          rule_type: r.rule_type,
          value: r.value,
        })),
      );
    }
  }, [rulesQuery.data]);

  const services = servicesQuery.data ?? [];
  const serviceName = (id: number) => services.find((s) => s.id === id)?.name ?? `Servicio #${id}`;
  const serviceCategory = (id: number) => services.find((s) => s.id === id)?.category ?? null;

  // Only categories the workspace actually has aranceles for — offering
  // "Odontología" to a clinic with no dental services is noise.
  const availableCategories = [...new Set(services.filter((s) => s.is_active && s.category).map((s) => s.category!))];

  /**
   * Applies one rule to every active service of a category.
   *
   * The reason this exists: a clinic laboratory is run by a technician, not a
   * physician, so those lines usually should not pay the attending doctor a
   * commission — but doing that service by service is fifteen additions per
   * doctor, which is exactly the kind of chore that never gets done. The clinic
   * still decides the policy; this only stops the policy from being unaffordable
   * to express.
   */
  const applyToCategory = () => {
    if (!bulkCategory || bulkValue.trim() === '') return;
    const value = Number(bulkValue);
    if (Number.isNaN(value) || value < 0) return;
    if (bulkType === 'percentage' && value > 100) {
      toast('El porcentaje no puede superar 100.', 'error');
      return;
    }

    const targets = services.filter((s) => s.is_active && s.category === bulkCategory);
    if (targets.length === 0) return;

    setRules((prev) => [
      ...prev.filter((r) => !targets.some((t) => t.id === r.medical_service_id)),
      ...targets.map((t) => ({ medical_service_id: t.id, rule_type: bulkType, value: bulkValue })),
    ]);
    toast(
      `${targets.length} servicio(s) de ${CATEGORY_LABELS[bulkCategory] ?? bulkCategory} al ${
        bulkType === 'percentage' ? `${value}%` : `$${value.toFixed(2)}`
      }. Revisa y guarda.`,
      'info',
    );
    setBulkValue('');
  };

  const addRule = () => {
    if (newServiceId === '' || newValue.trim() === '') return;
    const value = Number(newValue);
    if (Number.isNaN(value) || value < 0) return;
    if (newType === 'percentage' && value > 100) {
      toast('El porcentaje no puede superar 100.', 'error');
      return;
    }
    setRules((prev) => [
      ...prev.filter((r) => r.medical_service_id !== newServiceId),
      { medical_service_id: Number(newServiceId), rule_type: newType, value: newValue },
    ]);
    setNewServiceId('');
    setNewValue('');
  };

  const removeRule = (serviceId: number) => setRules((prev) => prev.filter((r) => r.medical_service_id !== serviceId));

  const save = useMutation({
    mutationFn: () =>
      setCommissionRules(
        memberId,
        rules.map((r) => ({ medical_service_id: r.medical_service_id, rule_type: r.rule_type, value: Number(r.value) })),
      ),
    onSuccess: () => {
      toast('Reparto por servicio guardado.', 'success');
      onSaved();
      onClose();
    },
    onError: () => toast('No se pudo guardar el reparto por servicio.', 'error'),
  });

  return (
    <Modal
      title={`Reparto por servicio — ${memberName}`}
      onClose={onClose}
      size="lg"
      footer={
        <>
          <button className="btn ghost" type="button" onClick={onClose}>Cancelar</button>
          <button className="btn primary" type="button" disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? 'Guardando…' : 'Guardar'}
          </button>
        </>
      }
    >
      <p className="muted" style={{ fontSize: 13, marginBottom: 12 }}>
        Define cuánto recibe el médico por servicio: un <b>porcentaje</b> de lo cobrado o un <b>monto fijo</b> por
        unidad. Los servicios sin regla usan el <b>% general</b> del médico.
      </p>

      {(rulesQuery.isLoading || servicesQuery.isLoading) && <Spinner label="Cargando…" />}

      {!rulesQuery.isLoading && (
        <>
          {rules.length === 0 ? (
            <p className="muted" style={{ fontSize: 13 }}>Sin reglas por servicio (se usa el % general).</p>
          ) : (
            <table className="tbl">
              <thead>
                <tr><th>Servicio</th><th>Tipo</th><th className="num">Valor</th><th></th></tr>
              </thead>
              <tbody>
                {rules.map((r) => (
                  <tr key={r.medical_service_id}>
                    <td>
                      {serviceName(r.medical_service_id)}
                      {serviceCategory(r.medical_service_id) && (
                        <span className="muted" style={{ display: 'block', fontSize: 11.5 }}>
                          {CATEGORY_LABELS[serviceCategory(r.medical_service_id)!] ?? serviceCategory(r.medical_service_id)}
                        </span>
                      )}
                    </td>
                    <td>{r.rule_type === 'percentage' ? 'Porcentaje' : 'Monto fijo'}</td>
                    <td className="num mono">
                      {r.rule_type === 'percentage' ? `${Number(r.value)}%` : `$${Number(r.value).toFixed(2)}`}
                    </td>
                    <td className="num">
                      <button className="btn sm ghost" type="button" title="Quitar" onClick={() => removeRule(r.medical_service_id)}>
                        <Icon name="trash" size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {availableCategories.length > 0 && (
            <div className="commission-bulk">
              <h5>Aplicar a una categoría completa</h5>
              <p className="muted">
                El laboratorio y la imagenología suele ejecutarlos personal no médico. Si en tu
                clínica es así, ponlos en <b>0 %</b>: de lo contrario el médico que atendió cobra
                su porcentaje general sobre ese trabajo.
              </p>
              <div className="row" style={{ gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
                <div style={{ flex: '1 1 180px' }}>
                  <label className="field-label" htmlFor="bulk-cat">Categoría</label>
                  <select
                    id="bulk-cat"
                    className="field"
                    value={bulkCategory}
                    onChange={(e) => setBulkCategory(e.target.value)}
                  >
                    <option value="">Seleccionar…</option>
                    {availableCategories.map((c) => (
                      <option key={c} value={c}>
                        {CATEGORY_LABELS[c] ?? c}
                        {` (${services.filter((s) => s.is_active && s.category === c).length})`}
                      </option>
                    ))}
                  </select>
                </div>
                <div style={{ width: 130 }}>
                  <label className="field-label" htmlFor="bulk-type">Tipo</label>
                  <select
                    id="bulk-type"
                    className="field"
                    value={bulkType}
                    onChange={(e) => setBulkType(e.target.value as 'percentage' | 'fixed')}
                  >
                    <option value="percentage">Porcentaje</option>
                    <option value="fixed">Monto fijo</option>
                  </select>
                </div>
                <div style={{ width: 120 }}>
                  <label className="field-label" htmlFor="bulk-value">
                    {bulkType === 'percentage' ? '% (0–100)' : 'Monto $'}
                  </label>
                  <input
                    id="bulk-value"
                    className="field"
                    type="number"
                    min={0}
                    max={bulkType === 'percentage' ? 100 : undefined}
                    step="0.01"
                    value={bulkValue}
                    onChange={(e) => setBulkValue(e.target.value)}
                  />
                </div>
                <button
                  className="btn"
                  type="button"
                  onClick={applyToCategory}
                  disabled={!bulkCategory || bulkValue.trim() === ''}
                >
                  <Icon name="check" size={15} /> Aplicar a la categoría
                </button>
              </div>
            </div>
          )}

          <div className="row" style={{ gap: 8, alignItems: 'flex-end', marginTop: 14, flexWrap: 'wrap' }}>
            <div style={{ flex: '1 1 200px' }}>
              <label className="field-label">Servicio</label>
              <select
                className="field"
                value={newServiceId}
                onChange={(e) => setNewServiceId(e.target.value === '' ? '' : Number(e.target.value))}
              >
                <option value="">Seleccionar…</option>
                {services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                    {s.category ? ` · ${CATEGORY_LABELS[s.category] ?? s.category}` : ''}
                  </option>
                ))}
              </select>
            </div>
            <div style={{ width: 130 }}>
              <label className="field-label">Tipo</label>
              <select className="field" value={newType} onChange={(e) => setNewType(e.target.value as 'percentage' | 'fixed')}>
                <option value="percentage">Porcentaje</option>
                <option value="fixed">Monto fijo</option>
              </select>
            </div>
            <div style={{ width: 120 }}>
              <label className="field-label">{newType === 'percentage' ? '% (0–100)' : 'Monto $'}</label>
              <input
                className="field"
                type="number"
                min={0}
                max={newType === 'percentage' ? 100 : undefined}
                step="0.01"
                value={newValue}
                onChange={(e) => setNewValue(e.target.value)}
              />
            </div>
            <button className="btn" type="button" onClick={addRule} disabled={newServiceId === '' || newValue.trim() === ''}>
              <Icon name="plus" size={15} /> Agregar
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}
