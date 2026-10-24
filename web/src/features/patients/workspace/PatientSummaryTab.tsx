import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { addAllergy, deleteAllergy, fetchPatientRecords } from '@/api/patients';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { Modal } from '@/components/ui/Modal';
import { PatientAlertsPanel } from '@/features/patients/PatientAlerts';
import { formatDate } from '@/i18n/es';
import { PERSONAL_BACKGROUND_LABELS } from '@/i18n/labels';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { GeneralDataCard } from './GeneralDataCard';
import { usePatientWorkspace } from './context';
import { PatientVitalsCard } from './PatientVitalsCard';
import type { Patient, PatientAllergy } from '@/api/types';

// ─── Allergy form ────────────────────────────────────────────────────────────

const allergySchema = z.object({
  allergen_name: z.string().min(1, 'Requerido'),
  allergen_type: z.enum(['drug', 'food', 'environmental', 'other']).optional(),
  severity:      z.enum(['mild', 'moderate', 'severe']).optional(),
  notes:         z.string().optional(),
});
type AllergyForm = z.infer<typeof allergySchema>;

const ALLERGEN_TYPE_LABELS: Record<string, string> = {
  drug:          'Medicamento',
  food:          'Alimento',
  environmental: 'Ambiental',
  other:         'Otro',
};

const SEVERITY_LABELS: Record<string, string> = {
  mild:     'Leve',
  moderate: 'Moderada',
  severe:   'Severa',
};

function AddAllergyModal({
  patientId,
  onClose,
}: {
  patientId: number;
  onClose: () => void;
}) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();

  const { register, handleSubmit, formState } = useForm<AllergyForm>({
    resolver: zodResolver(allergySchema),
  });

  const mutation = useMutation({
    mutationFn: (values: AllergyForm) => addAllergy(patientId, values),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
      onClose();
    },
  });

  const apiError = mutation.isError
    ? (isAxiosError(mutation.error) &&
        (mutation.error.response?.data as { message?: string })?.message) ||
      'No se pudo registrar la alergia.'
    : null;

  return (
    <Modal
      title="Nueva alergia"
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>Cancelar</button>
          <button
            className="btn primary"
            form="add-allergy"
            type="submit"
            disabled={mutation.isPending}
          >
            {mutation.isPending ? 'Guardando…' : 'Agregar alergia'}
          </button>
        </>
      }
    >
      <form
        id="add-allergy"
        className="form-grid"
        onSubmit={handleSubmit((v) => mutation.mutate(v))}
      >
        <div className="full">
          <label className="field-label">Alérgeno *</label>
          <input className="field" placeholder="Ej: Penicilina, Polen, Mariscos…" {...register('allergen_name')} />
          {formState.errors.allergen_name && (
            <p className="field-error">{formState.errors.allergen_name.message}</p>
          )}
        </div>

        <div>
          <label className="field-label">Tipo</label>
          <select className="field" {...register('allergen_type')}>
            <option value="">— sin especificar —</option>
            {Object.entries(ALLERGEN_TYPE_LABELS).map(([v, label]) => (
              <option key={v} value={v}>{label}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="field-label">Severidad</label>
          <select className="field" {...register('severity')}>
            <option value="">— sin especificar —</option>
            {Object.entries(SEVERITY_LABELS).map(([v, label]) => (
              <option key={v} value={v}>{label}</option>
            ))}
          </select>
        </div>

        <div className="full">
          <label className="field-label">Notas</label>
          <textarea className="field" rows={2} {...register('notes')} />
        </div>

        {apiError && <p className="field-error full">{apiError}</p>}
      </form>
    </Modal>
  );
}

// ─── Allergy row ─────────────────────────────────────────────────────────────

function AllergyRow({
  allergy,
  patientId,
}: {
  allergy: PatientAllergy;
  patientId: number;
}) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const [confirming, setConfirming] = useState(false);

  const remove = useMutation({
    mutationFn: () => deleteAllergy(patientId, allergy.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] }),
  });

  const severityLabel = allergy.severity ? (SEVERITY_LABELS[allergy.severity] ?? allergy.severity) : null;
  const typeLabel     = allergy.allergen_type ? (ALLERGEN_TYPE_LABELS[allergy.allergen_type] ?? allergy.allergen_type) : null;

  return (
    <div className="alert red" style={{ alignItems: 'flex-start' }}>
      <span className="ai"><Icon name="alert" size={15} /></span>
      <div style={{ flex: 1 }}>
        <b>Alergia: {allergy.allergen_name ?? '—'}</b>
        <p style={{ margin: 0 }}>
          {[typeLabel, severityLabel, allergy.notes].filter(Boolean).join(' — ') || 'Sin detalle.'}
        </p>
      </div>
      {confirming ? (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <span style={{ fontSize: 12, color: 'var(--red)' }}>¿Eliminar?</span>
          <button
            className="btn sm danger"
            onClick={() => remove.mutate()}
            disabled={remove.isPending}
          >
            {remove.isPending ? '…' : 'Sí'}
          </button>
          <button className="btn sm ghost" onClick={() => setConfirming(false)}>No</button>
        </div>
      ) : (
        <button
          className="btn sm ghost"
          style={{ flexShrink: 0 }}
          onClick={() => setConfirming(true)}
          title="Eliminar alergia"
        >
          <Icon name="trash" size={14} />
        </button>
      )}
    </div>
  );
}

// ─── Personal background ──────────────────────────────────────────────────────

function PersonalBackground({ data }: { data: Patient['personal_background'] }) {
  if (!data || typeof data !== 'object') return null;
  const entries = Object.entries(data).filter(
    ([, v]) =>
      v != null && v !== '' && (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean'),
  );
  if (entries.length === 0) return null;
  return (
    <div className="file">
      <div className="ft ft-amber">
        Px
      </div>
      <div>
        <b>Antecedentes personales</b>
        <span className="muted" style={{ display: 'block', fontSize: 12 }}>
          {entries.map(([k, v]) => `${PERSONAL_BACKGROUND_LABELS[k] ?? k}: ${String(v)}`).join(' · ')}
        </span>
      </div>
    </div>
  );
}

// ─── Última consulta ─────────────────────────────────────────────────────────

/** Resumen de qué fue la última consulta, sin tener que abrir la línea de
 *  tiempo completa. Comparte query key con `PatientHistoryTab`/`PatientOrdersTab`
 *  (['patient-records', ...]), así que no dispara una petición aparte si ya
 *  se cargó en esta sesión. */
function LastRecordSummary({ patientId }: { patientId: number }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const { data, isLoading } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
  });

  // Salta cualquier registro que no esté 'completed': un borrador de
  // enfermería o una consulta autoguardada a medio escribir (ver bloque D,
  // EncounterPage) no es todavía "la última consulta" del paciente.
  const last = data?.data?.find((r) => r.status === 'completed');

  if (isLoading) return <p className="muted">Cargando…</p>;
  if (!last) return <p className="muted">Sin consultas registradas todavía.</p>;

  return (
    <div className="stack" style={{ gap: 6 }}>
      <div className="row between">
        <b>{last.reason_for_consultation ?? 'Consulta'}</b>
        <span className="muted" style={{ fontSize: 12 }}>{formatDate(last.created_at)}</span>
      </div>
      {last.diagnosis && <p className="muted" style={{ margin: 0 }}>{last.diagnosis}</p>}
      {last.diagnoses && last.diagnoses.length > 0 && (
        <div className="row wrap" style={{ gap: 6 }}>
          {last.diagnoses.map((d) => (
            <span key={d.id} className="badge blue mono" title={d.cie10_description ?? undefined}>
              {d.cie10_code}
            </span>
          ))}
        </div>
      )}
      <Link
        className="btn sm ghost"
        to={`/pacientes/${patientId}/historial`}
        style={{ alignSelf: 'flex-start', marginTop: 4 }}
      >
        Ver historial completo
      </Link>
    </div>
  );
}

// ─── Tab ──────────────────────────────────────────────────────────────────────

export function PatientSummaryTab() {
  const { patient, patientId } = usePatientWorkspace();
  const { can } = useWorkspaceCapabilities();
  const canViewRecords = can('records.view');
  const canCaptureVitals = can('vitals.create');
  const allergies = patient.allergies ?? [];
  const family    = patient.family_background ?? [];

  const [showAddAllergy, setShowAddAllergy] = useState(false);

  return (
    <div className="patient-summary-grid">
      <div className="patient-summary-column">
        <GeneralDataCard patient={patient} />

        {/* Signs and last consultation stay below demographics, without the
            unrelated right column setting their vertical position. */}
        {(canCaptureVitals || canViewRecords) && (
          <Card>
            <div className="card-head flush">
              <h3>Estado clínico reciente</h3>
            </div>
            <div className="stack" style={{ gap: 16 }}>
              {canCaptureVitals && (
                <div>
                  <label className="field-label">Signos vitales</label>
                  <PatientVitalsCard patientId={patientId} />
                </div>
              )}
              {canViewRecords && (
                <div>
                  <label className="field-label">Última consulta</label>
                  <LastRecordSummary patientId={patientId} />
                </div>
              )}
            </div>
          </Card>
        )}
      </div>

      <div className="patient-summary-column">

      {/* ── Avisos permanentes ──
          Junto a las alergias porque son hermanos: lo que hay que saber del
          paciente antes de decidir nada, y que se pinta en cada pantalla. */}
      {canViewRecords && (
        <Card>
          <div className="card-head flush">
            <h3>Avisos</h3>
          </div>
          <PatientAlertsPanel patientId={patientId} />
        </Card>
      )}

      {/* ── Antecedentes y alergias ── */}
      <Card>
        <div className="card-head flush">
          <h3>Antecedentes y alergias</h3>
          <button className="btn sm ghost" onClick={() => setShowAddAllergy(true)}>
            <Icon name="plus" size={14} /> Agregar alergia
          </button>
        </div>
        <div className="stack" style={{ gap: 12 }}>
          {allergies.length === 0 && family.length === 0 && !patient.personal_background && (
            <p className="muted">Sin antecedentes registrados.</p>
          )}

          {allergies.map((allergy) => (
            <AllergyRow key={allergy.id} allergy={allergy} patientId={patientId} />
          ))}

          {family.map((entry) => (
            <div key={entry.id} className="file">
              <div className="ft ft-blue">
                Fx
              </div>
              <div>
                <b>Antecedente familiar{entry.relative_type ? ` · ${entry.relative_type}` : ''}</b>
                <span className="muted" style={{ fontSize: 12, display: 'block' }}>
                  {[entry.disease, entry.notes].filter(Boolean).join(' — ') || '—'}
                </span>
              </div>
            </div>
          ))}

          <PersonalBackground data={patient.personal_background} />
        </div>
      </Card>
      </div>

      {showAddAllergy && (
        <AddAllergyModal patientId={patientId} onClose={() => setShowAddAllergy(false)} />
      )}
    </div>
  );
}
