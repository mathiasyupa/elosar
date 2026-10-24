import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  deleteImmunization,
  fetchPatientImmunizations,
  fetchVaccines,
  recordImmunization,
} from '@/api/immunizations';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { formatDate, t } from '@/i18n/es';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { usePatientWorkspace } from './context';
import type { DoseLabel, Immunization, Vaccine } from '@/api/types';

interface Slot {
  label: DoseLabel;
  header: string;
  kind: 'dose' | 'booster';
  idx: number;
}

const SLOTS: Slot[] = [
  { label: 'dose_1', header: 'Dosis 1', kind: 'dose', idx: 1 },
  { label: 'dose_2', header: 'Dosis 2', kind: 'dose', idx: 2 },
  { label: 'dose_3', header: 'Dosis 3', kind: 'dose', idx: 3 },
  { label: 'booster_1', header: 'Refuerzo 1', kind: 'booster', idx: 1 },
  { label: 'booster_2', header: 'Refuerzo 2', kind: 'booster', idx: 2 },
  { label: 'booster_3', header: 'Refuerzo 3', kind: 'booster', idx: 3 },
];

const vaccineKey = (im: Pick<Immunization, 'vaccine_id' | 'vaccine_name'>): string =>
  im.vaccine_id != null ? `id:${im.vaccine_id}` : `name:${im.vaccine_name.toLowerCase()}`;

const rowKey = (v: Vaccine): string => `id:${v.id}`;

interface RecordTarget {
  vaccine: Vaccine | null;
  vaccine_name: string;
  dose_label: DoseLabel;
}

export function PatientVaccinesTab() {
  const { patientId } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const { can } = useWorkspaceCapabilities();
  const qc = useQueryClient();
  const canManage = can('vitals.create');

  const [target, setTarget] = useState<RecordTarget | null>(null);
  const [form, setForm] = useState({ administered_at: '', lot: '', notes: '', reminder_at: '' });

  const { data: vaccines = [], isLoading: vaccinesLoading } = useQuery({
    queryKey: ['vaccines', activeWorkspaceId],
    queryFn: fetchVaccines,
  });
  const { data: doses = [], isLoading: dosesLoading } = useQuery({
    queryKey: ['immunizations', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientImmunizations(patientId),
  });

  // Index administered doses by vaccine + dose slot for quick cell lookup.
  const doseIndex = useMemo(() => {
    const map = new Map<string, Immunization>();
    for (const d of doses) map.set(`${vaccineKey(d)}|${d.dose_label}`, d);
    return map;
  }, [doses]);

  // Doses whose vaccine is not in the catalog → shown in a separate list.
  const offCatalog = useMemo(() => {
    const known = new Set(vaccines.map((v) => `id:${v.id}`));
    const knownNames = new Set(vaccines.map((v) => `name:${v.name.toLowerCase()}`));
    return doses.filter((d) => !known.has(vaccineKey(d)) && !knownNames.has(vaccineKey(d)));
  }, [doses, vaccines]);

  const cellFor = (v: Vaccine, slot: Slot): Immunization | undefined =>
    doseIndex.get(`${rowKey(v)}|${slot.label}`) ?? doseIndex.get(`name:${v.name.toLowerCase()}|${slot.label}`);

  const recordMutation = useMutation({
    mutationFn: () =>
      recordImmunization(patientId, {
        vaccine_id: target?.vaccine?.id ?? null,
        vaccine_name: target?.vaccine_name ?? '',
        dose_label: target!.dose_label,
        administered_at: form.administered_at,
        lot: form.lot.trim() || null,
        notes: form.notes.trim() || null,
        reminder_at: form.reminder_at || null,
      }),
    onSuccess: () => {
      toast('Dosis registrada.', 'success');
      qc.invalidateQueries({ queryKey: ['immunizations', activeWorkspaceId, patientId] });
      setTarget(null);
    },
    onError: () => toast('No se pudo registrar la dosis.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteImmunization(id),
    onSuccess: () => {
      toast('Dosis eliminada.', 'success');
      qc.invalidateQueries({ queryKey: ['immunizations', activeWorkspaceId, patientId] });
    },
    onError: () => toast('No se pudo eliminar la dosis.', 'error'),
  });

  const openRecord = (vaccine: Vaccine, slot: Slot) => {
    setTarget({ vaccine, vaccine_name: vaccine.name, dose_label: slot.label });
    setForm({ administered_at: ecuadorDateInputValue(), lot: '', notes: '', reminder_at: '' });
  };

  if (vaccinesLoading || dosesLoading) return <Spinner label={t.common.loading} />;

  return (
    <div className="stack" style={{ gap: 18 }}>
      <Card padded={false}>
        <div className="card-head">
          <div>
            <h3>Esquema de vacunación (PAI)</h3>
            <span className="sub">Carnet de vacunación del paciente — registra las dosis administradas.</span>
          </div>
        </div>
        <div style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th style={{ minWidth: 220 }}>Vacuna</th>
                {SLOTS.map((s) => (
                  <th key={s.label} style={{ textAlign: 'center', whiteSpace: 'nowrap' }}>{s.header}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {vaccines.map((v) => (
                <tr key={v.id}>
                  <td>
                    <b>{v.name}</b>
                    {v.category && v.category !== 'PAI' && (
                      <span className="muted" style={{ display: 'block', fontSize: 11.5 }}>{v.category}</span>
                    )}
                  </td>
                  {SLOTS.map((slot) => {
                    const applicable =
                      slot.kind === 'dose' ? slot.idx <= v.max_doses : slot.idx <= v.max_boosters;
                    if (!applicable) {
                      return <td key={slot.label} style={{ textAlign: 'center', color: 'var(--border)' }}>·</td>;
                    }
                    const dose = cellFor(v, slot);
                    return (
                      <td key={slot.label} style={{ textAlign: 'center', whiteSpace: 'nowrap' }}>
                        {dose ? (
                          <button
                            type="button"
                            className="badge"
                            title={[
                              dose.lot ? `Lote: ${dose.lot}` : null,
                              dose.notes || null,
                              dose.reminder_at ? `Próxima: ${formatDate(dose.reminder_at)}` : null,
                              canManage ? 'Clic para eliminar' : null,
                            ].filter(Boolean).join('\n')}
                            onClick={() => {
                              if (canManage && window.confirm('¿Eliminar esta dosis?')) deleteMutation.mutate(dose.id);
                            }}
                            style={{ cursor: canManage ? 'pointer' : 'default' }}
                          >
                            {formatDate(dose.administered_at)}
                            {dose.reminder_at && <Icon name="bell" size={11} style={{ marginLeft: 4 }} />}
                          </button>
                        ) : canManage ? (
                          <button type="button" className="icon-btn" aria-label="Registrar dosis" onClick={() => openRecord(v, slot)}>
                            <Icon name="plus" size={15} />
                          </button>
                        ) : (
                          <span className="muted">—</span>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {offCatalog.length > 0 && (
        <Card padded={false}>
          <div className="card-head">
            <h3>Otras dosis registradas</h3>
          </div>
          <table className="tbl">
            <thead>
              <tr><th>Vacuna</th><th>Dosis</th><th>Fecha</th><th>Lote</th><th></th></tr>
            </thead>
            <tbody>
              {offCatalog.map((d) => (
                <tr key={d.id}>
                  <td><b>{d.vaccine_name}</b></td>
                  <td>{d.dose_label_text}</td>
                  <td>{formatDate(d.administered_at)}</td>
                  <td>{d.lot ?? '—'}</td>
                  <td style={{ textAlign: 'right' }}>
                    {canManage && (
                      <button
                        className="icon-btn"
                        aria-label="Eliminar dosis"
                        onClick={() => { if (window.confirm('¿Eliminar esta dosis?')) deleteMutation.mutate(d.id); }}
                      >
                        <Icon name="trash" size={16} />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {target && (
        <Modal
          title={`${target.vaccine_name} — ${SLOTS.find((s) => s.label === target.dose_label)?.header}`}
          onClose={() => setTarget(null)}
          footer={
            <>
              <button className="btn ghost" type="button" onClick={() => setTarget(null)}>Cancelar</button>
              <button
                className="btn primary"
                type="button"
                disabled={!form.administered_at || recordMutation.isPending}
                onClick={() => recordMutation.mutate()}
              >
                {recordMutation.isPending ? 'Guardando…' : 'Registrar dosis'}
              </button>
            </>
          }
        >
          <div className="form-grid">
            <div>
              <label className="field-label">Fecha de administración *</label>
              <input
                className="field"
                type="date"
                value={form.administered_at}
                onChange={(e) => setForm((f) => ({ ...f, administered_at: e.target.value }))}
              />
            </div>
            <div>
              <label className="field-label">Lote</label>
              <input
                className="field"
                placeholder="N° de lote"
                value={form.lot}
                onChange={(e) => setForm((f) => ({ ...f, lot: e.target.value }))}
              />
            </div>
            <div>
              <label className="field-label">Recordatorio próxima dosis</label>
              <input
                className="field"
                type="date"
                value={form.reminder_at}
                onChange={(e) => setForm((f) => ({ ...f, reminder_at: e.target.value }))}
              />
            </div>
            <div className="full">
              <label className="field-label">Observaciones</label>
              <textarea
                className="field"
                rows={2}
                value={form.notes}
                onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
              />
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
