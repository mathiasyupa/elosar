import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  addAllergy,
  addFamilyBackground,
  deleteAllergy,
  deleteFamilyBackground,
  updatePatient,
} from '@/api/patients';
import { Icon } from '@/components/Icon';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import type { Patient } from '@/api/types';

const RELATIVE_TYPES: { value: string; label: string }[] = [
  { value: 'father', label: 'Padre' },
  { value: 'mother', label: 'Madre' },
  { value: 'brother', label: 'Hermano' },
  { value: 'sister', label: 'Hermana' },
  { value: 'son', label: 'Hijo' },
  { value: 'daughter', label: 'Hija' },
  { value: 'paternal_grandfather', label: 'Abuelo paterno' },
  { value: 'paternal_grandmother', label: 'Abuela paterna' },
  { value: 'maternal_grandfather', label: 'Abuelo materno' },
  { value: 'maternal_grandmother', label: 'Abuela materna' },
  { value: 'uncle', label: 'Tío' },
  { value: 'aunt', label: 'Tía' },
  { value: 'other', label: 'Otro' },
];

type PersonalBackgroundForm = {
  allergies: string;
  surgeries: string;
  current_medication: string;
  tobacco: boolean;
  alcohol: string;
};

function readPersonalBackground(patient: Patient): PersonalBackgroundForm {
  const bg = (patient.personal_background ?? {}) as Record<string, unknown>;
  const habits = (bg.habits ?? {}) as Record<string, unknown>;
  return {
    allergies: typeof bg.allergies === 'string' ? bg.allergies : '',
    surgeries: typeof bg.surgeries === 'string' ? bg.surgeries : '',
    current_medication: typeof bg.current_medication === 'string' ? bg.current_medication : '',
    tobacco: habits.tobacco === true,
    alcohol: typeof habits.alcohol === 'string' ? habits.alcohol : '',
  };
}

interface PatientHistoryPanelProps {
  patient: Patient;
  patientId: number;
}

/** Takes the patient as props rather than reading the expediente's Outlet
 *  context: its only caller is the encounter screen, which lives outside that
 *  layout. `useOutletContext` is typed as always returning the context, so
 *  reading it from outside compiles fine and then throws at runtime. */
export function PatientHistoryPanel({ patient, patientId }: PatientHistoryPanelProps) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const [open, setOpen] = useState(false);
  const [personal, setPersonal] = useState(() => readPersonalBackground(patient));
  const [newAllergy, setNewAllergy] = useState({ allergen_name: '', severity: 'moderate' });
  const [newFamily, setNewFamily] = useState({ relative_type: 'mother', disease: '', notes: '' });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
  };

  const savePersonal = useMutation({
    mutationFn: () =>
      updatePatient(patientId, {
        personal_background: {
          allergies: personal.allergies || null,
          surgeries: personal.surgeries || null,
          current_medication: personal.current_medication || null,
          habits: {
            tobacco: personal.tobacco,
            alcohol: personal.alcohol || null,
          },
        },
      }),
    onSuccess: () => {
      invalidate();
      toast('Antecedentes personales actualizados.', 'success');
    },
    onError: () => toast('No se pudieron guardar los antecedentes.', 'error'),
  });

  const addAllergyMut = useMutation({
    mutationFn: () =>
      addAllergy(patientId, {
        allergen_name: newAllergy.allergen_name.trim(),
        allergen_type: 'drug',
        severity: newAllergy.severity,
      }),
    onSuccess: () => {
      setNewAllergy({ allergen_name: '', severity: 'moderate' });
      invalidate();
      toast('Alergia registrada.', 'success');
    },
    onError: () => toast('No se pudo registrar la alergia.', 'error'),
  });

  const removeAllergyMut = useMutation({
    mutationFn: (id: number) => deleteAllergy(patientId, id),
    onSuccess: invalidate,
  });

  const addFamilyMut = useMutation({
    mutationFn: () =>
      addFamilyBackground(patientId, {
        relative_type: newFamily.relative_type,
        disease: newFamily.disease.trim(),
        notes: newFamily.notes.trim() || null,
      }),
    onSuccess: () => {
      setNewFamily({ relative_type: 'mother', disease: '', notes: '' });
      invalidate();
      toast('Antecedente familiar añadido.', 'success');
    },
    onError: () => toast('No se pudo añadir el antecedente.', 'error'),
  });

  const removeFamilyMut = useMutation({
    mutationFn: (id: number) => deleteFamilyBackground(patientId, id),
    onSuccess: invalidate,
  });

  const allergies = patient.allergies ?? [];
  const family = patient.family_background ?? [];

  return (
    <div style={{ marginTop: 20, borderTop: '1px solid var(--border)', paddingTop: 16 }}>
      <button
        className="btn ghost sm"
        style={{ width: '100%', justifyContent: 'space-between' }}
        onClick={() => setOpen((v) => !v)}
        type="button"
      >
        <span>
          <Icon name="file" size={15} /> Antecedentes del paciente
        </span>
        <Icon name="chevron" size={14} style={{ transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }} />
      </button>

      {open && (
        <div className="stack" style={{ gap: 20, marginTop: 16 }}>
          <section>
            <h5 style={{ margin: '0 0 10px', fontSize: 13 }}>Alergias estructuradas</h5>
            {allergies.length > 0 ? (
              <div className="stack" style={{ gap: 6, marginBottom: 10 }}>
                {allergies.map((a) => (
                  <div key={a.id} className="row between" style={{ fontSize: 13 }}>
                    <span>
                      <strong>{a.allergen_name ?? a.allergen_type}</strong>
                      {a.severity ? ` · ${a.severity}` : ''}
                    </span>
                    <button
                      className="icon-btn"
                      onClick={() => removeAllergyMut.mutate(a.id)}
                      type="button"
                      aria-label="Eliminar alergia"
                    >
                      <Icon name="x" size={14} />
                    </button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="muted" style={{ fontSize: 12.5, marginBottom: 10 }}>
                Sin alergias registradas.
              </p>
            )}
            <div className="form-grid" style={{ gridTemplateColumns: '2fr 1fr auto', alignItems: 'end' }}>
              <div>
                <label className="field-label">Nueva alergia</label>
                <input
                  className="field"
                  placeholder="Ej. Penicilina"
                  value={newAllergy.allergen_name}
                  onChange={(e) => setNewAllergy((s) => ({ ...s, allergen_name: e.target.value }))}
                />
              </div>
              <div>
                <label className="field-label">Severidad</label>
                <select
                  className="field"
                  value={newAllergy.severity}
                  onChange={(e) => setNewAllergy((s) => ({ ...s, severity: e.target.value }))}
                >
                  <option value="mild">Leve</option>
                  <option value="moderate">Moderada</option>
                  <option value="severe">Severa</option>
                </select>
              </div>
              <button
                className="btn sm"
                disabled={!newAllergy.allergen_name.trim() || addAllergyMut.isPending}
                onClick={() => addAllergyMut.mutate()}
                type="button"
              >
                Añadir
              </button>
            </div>
          </section>

          <section>
            <h5 style={{ margin: '0 0 10px', fontSize: 13 }}>Antecedentes personales</h5>
            <div className="form-grid">
              <div>
                <label className="field-label">Alergias (texto libre)</label>
                <input
                  className="field"
                  value={personal.allergies}
                  onChange={(e) => setPersonal((s) => ({ ...s, allergies: e.target.value }))}
                />
              </div>
              <div>
                <label className="field-label">Cirugías previas</label>
                <input
                  className="field"
                  value={personal.surgeries}
                  onChange={(e) => setPersonal((s) => ({ ...s, surgeries: e.target.value }))}
                />
              </div>
              <div className="full">
                <label className="field-label">Medicación actual</label>
                <input
                  className="field"
                  value={personal.current_medication}
                  onChange={(e) => setPersonal((s) => ({ ...s, current_medication: e.target.value }))}
                />
              </div>
              <div>
                <label className="field-label">Tabaco</label>
                <label className="row" style={{ gap: 8, fontSize: 13, marginTop: 8 }}>
                  <input
                    type="checkbox"
                    checked={personal.tobacco}
                    onChange={(e) => setPersonal((s) => ({ ...s, tobacco: e.target.checked }))}
                  />
                  Usa tabaco
                </label>
              </div>
              <div>
                <label className="field-label">Alcohol</label>
                <select
                  className="field"
                  value={personal.alcohol}
                  onChange={(e) => setPersonal((s) => ({ ...s, alcohol: e.target.value }))}
                >
                  <option value="">—</option>
                  <option value="never">Nunca</option>
                  <option value="occasional">Ocasional</option>
                  <option value="frequent">Frecuente</option>
                </select>
              </div>
            </div>
            <button
              className="btn sm"
              style={{ marginTop: 10 }}
              disabled={savePersonal.isPending}
              onClick={() => savePersonal.mutate()}
              type="button"
            >
              Guardar antecedentes personales
            </button>
          </section>

          <section>
            <h5 style={{ margin: '0 0 10px', fontSize: 13 }}>Antecedentes familiares</h5>
            {family.length > 0 ? (
              <div className="stack" style={{ gap: 6, marginBottom: 10 }}>
                {family.map((f) => (
                  <div key={f.id} className="row between" style={{ fontSize: 13 }}>
                    <span>
                      {RELATIVE_TYPES.find((r) => r.value === f.relative_type)?.label ?? f.relative_type}:{' '}
                      <strong>{f.disease}</strong>
                    </span>
                    <button
                      className="icon-btn"
                      onClick={() => removeFamilyMut.mutate(f.id)}
                      type="button"
                      aria-label="Eliminar antecedente"
                    >
                      <Icon name="x" size={14} />
                    </button>
                  </div>
                ))}
              </div>
            ) : (
              <p className="muted" style={{ fontSize: 12.5, marginBottom: 10 }}>
                Sin antecedentes familiares registrados.
              </p>
            )}
            <div className="form-grid" style={{ gridTemplateColumns: '1fr 2fr auto', alignItems: 'end' }}>
              <div>
                <label className="field-label">Parentesco</label>
                <select
                  className="field"
                  value={newFamily.relative_type}
                  onChange={(e) => setNewFamily((s) => ({ ...s, relative_type: e.target.value }))}
                >
                  {RELATIVE_TYPES.map((r) => (
                    <option key={r.value} value={r.value}>
                      {r.label}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="field-label">Enfermedad / condición</label>
                <input
                  className="field"
                  value={newFamily.disease}
                  onChange={(e) => setNewFamily((s) => ({ ...s, disease: e.target.value }))}
                />
              </div>
              <button
                className="btn sm"
                disabled={!newFamily.disease.trim() || addFamilyMut.isPending}
                onClick={() => addFamilyMut.mutate()}
                type="button"
              >
                Añadir
              </button>
            </div>
          </section>
        </div>
      )}
    </div>
  );
}
