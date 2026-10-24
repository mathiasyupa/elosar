import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createWorkspaceMedication, searchMedications } from '@/api/catalogs';
import { fetchPatientRecords } from '@/api/patients';
import { Icon } from '@/components/Icon';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import type { MedicationCatalogItem, RecordPrescription } from '@/api/types';

/**
 * A prescription being written. Since 2026-09-21 the form captures only
 * `medication_name`, `quantity` and `instructions` (plus the controlled
 * switch): prescribing a cream asked for twelve fields and doctors said so.
 *
 * SIX fields survive as opaque carried strings — `generic_name`,
 * `concentration`, `dosage`, `frequency`, `duration_days` and `route`. They are
 * never edited or invented here. `rowFromHistory` populates all six (it
 * reproduces a prescription that was reviewed and signed when it was first
 * issued) and `rowFromCatalog` populates only `concentration`, which is the
 * drug's identity rather than a posology.
 *
 * All six describe ONE medication, so they are only valid while
 * `medication_name` still names that medication: see {@link renameMedication}.
 */
export interface PrescriptionRow {
  medication_name: string;
  generic_name: string;
  concentration: string;
  dosage: string;
  frequency: string;
  duration_days: string;
  quantity: string;
  route: string;
  instructions: string;
  is_controlled: boolean;
  catalog_id: number | null;
}

export function emptyRow(): PrescriptionRow {
  return {
    medication_name: '',
    generic_name: '',
    concentration: '',
    dosage: '',
    frequency: '',
    duration_days: '',
    quantity: '',
    route: '',
    instructions: '',
    is_controlled: false,
    catalog_id: null,
  };
}

/**
 * A catalog pick contributes identity (name, concentration, controlled flag)
 * and its default instructions — never its default posology. A catalog default
 * is meant to be reviewed before it is signed, and the screen that reviewed it
 * no longer exists; printing it unseen on a prescription is worse than an
 * empty field.
 */
export function rowFromCatalog(item: MedicationCatalogItem): PrescriptionRow {
  return {
    ...emptyRow(),
    medication_name: item.name,
    concentration: item.concentration ?? '',
    instructions: item.default_instructions ?? '',
    is_controlled: item.is_controlled,
    catalog_id: item.id,
  };
}

/**
 * Turns a stored prescription back into an editable row so the doctor can
 * repeat a previous one. The posology travels verbatim: it was reviewed and
 * signed when the prescription was first issued, so reproducing it is exactly
 * what "repetir la última receta" means — even though the form no longer shows
 * those fields.
 */
export function rowFromHistory(p: RecordPrescription): PrescriptionRow {
  return {
    ...emptyRow(),
    medication_name: p.medication_name?.trim() ?? '',
    generic_name: p.generic_name?.trim() ?? '',
    concentration: p.concentration?.trim() ?? '',
    dosage: p.dosage?.trim() ?? '',
    frequency: p.frequency?.trim() ?? '',
    duration_days: p.duration_days != null ? String(p.duration_days) : '',
    quantity: p.quantity != null ? String(p.quantity) : '',
    route: p.route ?? '',
    instructions: p.instructions?.trim() ?? '',
    is_controlled: p.is_controlled,
  };
}

/** Accent/case-insensitive normalization for allergy matching. */
function normalize(s: string): string {
  return s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

/**
 * Cross-reactive drug families (normalized tokens). When a patient is allergic
 * to any member, prescribing another member should still raise the warning —
 * e.g. "Penicilina" ⇄ "Amoxicilina". Kept short and clinically conservative
 * (the well-known beta-lactam and sulfonamide cross-reactivities); this is a
 * safety prompt, not a pharmacology engine.
 */
const DRUG_FAMILIES: string[][] = [
  // Penicillins / beta-lactams
  [
    'penicilina',
    'amoxicilina',
    'ampicilina',
    'dicloxacilina',
    'cloxacilina',
    'oxacilina',
    'flucloxacilina',
    'piperacilina',
    'nafcilina',
    'bencilpenicilina',
    'amoxiclav',
    'augmentin',
  ],
  // Sulfonamides
  ['sulfa', 'sulfonamida', 'sulfametoxazol', 'sulfadiazina', 'cotrimoxazol', 'trimetoprima'],
];

/**
 * Returns the patient allergy that conflicts with a medication name, or null.
 * Combines a simple substring match (allergy "Penicilina" ⊂ "Penicilina G")
 * with cross-reactive family lookup (penicillin ⇄ amoxicillin). A safety prompt
 * for the doctor, NOT a drug-interaction engine. Ignores very short tokens.
 */
function conflictingAllergy(medicationName: string, allergens: string[]): string | null {
  const med = normalize(medicationName);
  if (med.length < 3) return null;
  for (const allergen of allergens) {
    const a = normalize(allergen);
    if (a.length < 4) continue;
    if (med.includes(a) || a.includes(med)) return allergen;
    // Cross-reactive family: allergen and medication share the same family.
    for (const family of DRUG_FAMILIES) {
      if (family.some((t) => a.includes(t)) && family.some((t) => med.includes(t))) {
        return allergen;
      }
    }
  }
  return null;
}

export function prescriptionRowToPayload(row: PrescriptionRow) {
  return {
    medication_name: row.medication_name.trim(),
    generic_name: row.generic_name.trim() || undefined,
    concentration: row.concentration.trim() || undefined,
    dosage: row.dosage.trim() || undefined,
    route: row.route || undefined,
    frequency: row.frequency.trim() || undefined,
    duration_days: row.duration_days ? Number(row.duration_days) : undefined,
    quantity: row.quantity ? Number(row.quantity) : undefined,
    instructions: row.instructions.trim() || undefined,
    is_controlled: row.is_controlled,
  };
}

/** The collapsed summary of a row: what the doctor actually wrote. */
function previewLine(row: PrescriptionRow): string {
  return [
    row.medication_name,
    row.concentration || null,
    row.quantity ? `# ${row.quantity}` : null,
    row.instructions.trim() || null,
  ]
    .filter(Boolean)
    .join(' · ');
}

/**
 * Renames the medication of a row, dropping everything that described the OLD
 * one.
 *
 * The carried fields are invisible: the form has no input for a concentration
 * or a posology any more. So a doctor who repeats yesterday's Paracetamol
 * 500mg and types "Ibuprofeno" over the name would otherwise sign a
 * prescription reading "IBUPROFENO 500MG · 1 tableta, cada 8 horas, por 5
 * días" — the previous drug's numbers, on a new drug, with nowhere on screen
 * to notice or correct them.
 *
 * Carrying a hidden field is only ever safe while its anchor holds. Renaming
 * the medication breaks that anchor, so the carry ends here.
 */
export function renameMedication(row: PrescriptionRow, name: string): PrescriptionRow {
  if (name.trim() === row.medication_name.trim()) {
    // A pure re-typing of the same name (or whitespace) keeps what was carried.
    return { ...row, medication_name: name };
  }

  return {
    ...row,
    medication_name: name,
    catalog_id: null,
    generic_name: '',
    concentration: '',
    dosage: '',
    frequency: '',
    duration_days: '',
    route: '',
  };
}

/**
 * True when any named medication is missing its dispensing quantity — the MSP
 * "cantidad a dispensar", which a signed prescription may not leave blank.
 *
 * Lives here, with the row it describes, because both callers in
 * EncounterSection (finishing the visit and downloading the PDF) have to apply
 * the same rule, and two literal copies of a clinical rule drift.
 */
export function missingDispensingQuantity(rows: PrescriptionRow[]): boolean {
  return rows.some((row) => row.medication_name.trim() && !hasDispensingQuantity(row));
}

/**
 * True when this row's dispensing quantity is a whole number the server will
 * accept (`integer`, `min:1`).
 *
 * Checking for a non-empty string is not enough. `inputMode="numeric"` is a
 * keyboard hint, not a restriction, so "treinta" or "1 caja" get through;
 * `Number()` then turns them into `NaN`, JSON serializes that as `null`, and
 * the server answers 422 with a message about a field the doctor believes
 * they filled in. That is the "the button does nothing" complaint all over
 * again, through the one numeric field the form has left.
 */
export function hasDispensingQuantity(row: Pick<PrescriptionRow, 'quantity'>): boolean {
  const value = row.quantity.trim();
  return /^\d+$/.test(value) && Number(value) >= 1;
}

interface MedicationPickerProps {
  prescriptions: PrescriptionRow[];
  onChange: (rows: PrescriptionRow[]) => void;
  /** Patient's registered allergens (names) — drives prescribe-time warnings. */
  allergyNames?: string[];
  /** When set, enables "repeat the patient's last prescription". */
  patientId?: number;
}

export function MedicationPicker({ prescriptions, onChange, allergyNames = [], patientId }: MedicationPickerProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [expandedIndex, setExpandedIndex] = useState<number | null>(null);
  const [repeating, setRepeating] = useState(false);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const qc = useQueryClient();

  useEffect(() => {
    const t = window.setTimeout(() => setDebouncedQuery(searchQuery.trim()), 300);
    return () => window.clearTimeout(t);
  }, [searchQuery]);

  const { data: searchResults } = useQuery({
    queryKey: ['medications', activeWorkspaceId, debouncedQuery],
    queryFn: () => searchMedications(debouncedQuery),
    enabled: debouncedQuery.length >= 2,
  });

  const saveToCatalog = useMutation({
    mutationFn: (row: PrescriptionRow) =>
      createWorkspaceMedication({
        name: row.medication_name.trim(),
        concentration: row.concentration.trim() || null,
        is_controlled: row.is_controlled,
        default_instructions: row.instructions.trim() || null,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['medications'] });
      toast('Medicamento guardado en el catálogo de la clínica.', 'success');
    },
    onError: () => toast('No se pudo guardar en el catálogo.', 'error'),
  });

  const addBlank = () => {
    const next = [...prescriptions, emptyRow()];
    onChange(next);
    setExpandedIndex(next.length - 1);
  };

  const addFromCatalog = (item: MedicationCatalogItem) => {
    const next = [...prescriptions, rowFromCatalog(item)];
    onChange(next);
    setExpandedIndex(next.length - 1);
    setSearchQuery('');
  };

  // Load the medications from the patient's most recent consultation that had a
  // prescription, appended as editable rows the doctor can adjust or remove.
  const repeatLastPrescription = async () => {
    if (!patientId || repeating) return;
    setRepeating(true);
    try {
      const result = await qc.fetchQuery({
        queryKey: ['patient-records', activeWorkspaceId, patientId, 1],
        queryFn: () => fetchPatientRecords(patientId, 1),
      });
      const lastWithRx = result.data.find((r) => (r.prescriptions?.length ?? 0) > 0);
      const rows = (lastWithRx?.prescriptions ?? [])
        .filter((p) => p.medication_name?.trim())
        .map(rowFromHistory);
      if (rows.length === 0) {
        toast('Este paciente no tiene recetas previas para repetir.', 'info');
        return;
      }
      const next = [...prescriptions, ...rows];
      onChange(next);
      setExpandedIndex(next.length - 1);
      toast(`Se añadieron ${rows.length} medicamento(s) de la última receta. Revisa y ajusta.`, 'success');
    } catch {
      toast('No se pudo cargar la última receta.', 'error');
    } finally {
      setRepeating(false);
    }
  };

  const updateRow = (index: number, patch: Partial<PrescriptionRow>) => {
    onChange(prescriptions.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)));
  };

  /** Swaps a whole row, for changes that must CLEAR fields rather than merge
   *  over them — see {@link renameMedication}, where a plain patch would leave
   *  the previous medication's carried values in place. */
  const replaceRow = (index: number, next: PrescriptionRow) => {
    onChange(prescriptions.map((row, rowIndex) => (rowIndex === index ? next : row)));
  };

  const removeRow = (index: number) => {
    onChange(prescriptions.filter((_, i) => i !== index));
    setExpandedIndex((prev) => (prev === index ? null : prev != null && prev > index ? prev - 1 : prev));
  };

  const conflicts = prescriptions
    .map((row) => ({ name: row.medication_name.trim(), allergen: conflictingAllergy(row.medication_name, allergyNames) }))
    .filter((c): c is { name: string; allergen: string } => Boolean(c.allergen));

  return (
    <div className="stack" style={{ gap: 16 }}>
      {conflicts.length > 0 && (
        <div className="alert red" role="alert">
          <span className="ai"><Icon name="alert" size={15} /></span>
          <div>
            <b>Posible alergia</b>
            <p>
              {conflicts.map((c) => `${c.name} (alergia: ${c.allergen})`).join(' · ')} — verifica antes de prescribir.
            </p>
          </div>
        </div>
      )}

      <div className="card-head" style={{ padding: 0 }}>
        <div>
          <h4 style={{ margin: 0, fontSize: 14 }}>Receta</h4>
          <p className="muted" style={{ fontSize: 12.5, marginTop: 4 }}>
            Busca en el catálogo o añade manualmente: nombre, cantidad a dispensar e indicaciones.
          </p>
        </div>
        <div className="row" style={{ gap: 8 }}>
          {patientId && (
            <button
              className="btn sm"
              onClick={repeatLastPrescription}
              disabled={repeating}
              type="button"
              title="Cargar los medicamentos de la última consulta del paciente"
            >
              <Icon name="clock" size={14} /> {repeating ? 'Cargando…' : 'Repetir última receta'}
            </button>
          )}
          <button className="btn sm primary" onClick={addBlank} type="button">
            <Icon name="plus" size={14} /> Añadir medicamento
          </button>
        </div>
      </div>

      <div>
        <label className="field-label">Buscar medicamento</label>
        <div className="search">
          <Icon name="search" />
          <input
            placeholder="Ej. Paracetamol, Amoxicilina…"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </div>
        {searchResults && searchResults.length > 0 && (
          <div className="stack" style={{ gap: 4, marginTop: 8 }}>
            {searchResults.slice(0, 8).map((m) => {
              const hit = conflictingAllergy(m.name, allergyNames);
              return (
                <button key={m.id} className="ws-option" onClick={() => addFromCatalog(m)} type="button">
                  <span className="wo-meta">
                    <b>
                      {m.name}
                      {m.concentration ? ` ${m.concentration}` : ''}
                      {hit && (
                        <span className="badge red" style={{ marginLeft: 8 }} title={`Alergia: ${hit}`}>
                          Alergia
                        </span>
                      )}
                    </b>
                    {/* Only what the pick actually hands over. The catalog still
                        stores a default posology, but `rowFromCatalog` drops it
                        on purpose — advertising "1 tableta · cada 8 horas · 7
                        días" here and then producing a row with none of it, and
                        no field to write it in, promises something the form
                        cannot keep. */}
                    <span className="muted" style={{ display: 'block', fontSize: 12 }}>
                      {[
                        m.concentration,
                        !m.is_global ? 'Clínica' : null,
                        m.is_controlled ? 'Controlado' : null,
                      ]
                        .filter(Boolean)
                        .join(' · ') || 'Sin datos adicionales'}
                    </span>
                  </span>
                </button>
              );
            })}
          </div>
        )}
      </div>

      {prescriptions.length === 0 && (
        <p className="muted" style={{ fontSize: 13 }}>
          Sin medicamentos añadidos — opcional. Usa el buscador o «Añadir medicamento».
        </p>
      )}

      <div className="stack" style={{ gap: 12 }}>
        {prescriptions.map((row, i) => {
          const expanded = expandedIndex === i;
          const allergyHit = conflictingAllergy(row.medication_name, allergyNames);
          // Only once the row names a medication. A blank row is a row the
          // doctor just added and has not filled in yet; flagging it red
          // before they type anything reads as an error they caused.
          const quantityIsWrong = Boolean(row.medication_name.trim()) && !hasDispensingQuantity(row);
          return (
            <div
              key={i}
              className="enc-section"
              style={{
                border: '1px solid var(--border)',
                borderRadius: 12,
                padding: expanded ? 14 : '10px 14px',
                background: 'var(--surface)',
              }}
            >
              <div className="row between" style={{ alignItems: 'center', gap: 8 }}>
                <button
                  className="btn ghost sm"
                  style={{ flex: 1, justifyContent: 'flex-start', textAlign: 'left' }}
                  onClick={() => setExpandedIndex(expanded ? null : i)}
                  type="button"
                >
                  <Icon name={expanded ? 'chevron' : 'pill'} size={14} />
                  <span style={{ marginLeft: 8 }}>
                    {row.medication_name.trim() ? previewLine(row) : `Medicamento ${i + 1} — pendiente`}
                  </span>
                  {row.is_controlled && (
                    <span className="badge red" style={{ marginLeft: 8 }}>
                      Controlado
                    </span>
                  )}
                  {allergyHit && (
                    <span className="badge red" style={{ marginLeft: 8 }} title={`Alergia registrada: ${allergyHit}`}>
                      Alergia
                    </span>
                  )}
                </button>
                <button className="icon-btn" onClick={() => removeRow(i)} type="button" aria-label="Quitar">
                  <Icon name="trash" size={16} />
                </button>
              </div>

              {expanded && (
                <div className="form-grid" style={{ marginTop: 14, gap: 12 }}>
                  {allergyHit && (
                    <div className="alert red full" role="alert" style={{ margin: 0 }}>
                      <span className="ai"><Icon name="alert" size={15} /></span>
                      <p>Este paciente tiene alergia registrada a <b>{allergyHit}</b>. Verifica antes de prescribir.</p>
                    </div>
                  )}
                  <div>
                    <label className="field-label" htmlFor={`prescription-${i}-name`}>Nombre *</label>
                    <input
                      id={`prescription-${i}-name`}
                      className="field"
                      value={row.medication_name}
                      onChange={(e) => replaceRow(i, renameMedication(row, e.target.value))}
                    />
                  </div>
                  <div>
                    <label className="field-label" htmlFor={`prescription-${i}-quantity`}>
                      Cantidad a dispensar *
                    </label>
                    <input
                      id={`prescription-${i}-quantity`}
                      className="field"
                      inputMode="numeric"
                      placeholder="Ej. 30"
                      value={row.quantity}
                      onChange={(e) => updateRow(i, { quantity: e.target.value })}
                      aria-invalid={quantityIsWrong || undefined}
                    />
                    {quantityIsWrong && (
                      <p className="field-error">
                        {row.quantity.trim()
                          ? 'Debe ser un número entero de unidades. Ej. 30'
                          : 'Obligatoria — total que retira el paciente.'}
                      </p>
                    )}
                  </div>
                  <div className="full">
                    <label className="field-label" htmlFor={`prescription-${i}-instructions`}>
                      Indicaciones al paciente
                    </label>
                    <textarea
                      id={`prescription-${i}-instructions`}
                      className="field"
                      rows={3}
                      placeholder="Cómo se toma o se aplica, cada cuánto y por cuántos días."
                      value={row.instructions}
                      onChange={(e) => updateRow(i, { instructions: e.target.value })}
                    />
                  </div>
                  <div className="row wrap" style={{ gap: 12, alignItems: 'center' }}>
                    <label className="row" style={{ gap: 8, fontSize: 13 }}>
                      <input
                        type="checkbox"
                        checked={row.is_controlled}
                        onChange={(e) => updateRow(i, { is_controlled: e.target.checked })}
                      />
                      Sustancia controlada
                    </label>
                    {!row.catalog_id && row.medication_name.trim() && (
                      <button
                        className="btn sm"
                        disabled={saveToCatalog.isPending}
                        onClick={() => saveToCatalog.mutate(row)}
                        type="button"
                      >
                        Guardar en catálogo de la clínica
                      </button>
                    )}
                  </div>
                  <p className="muted full" style={{ fontSize: 12, margin: 0 }}>
                    Vista previa: <strong>{previewLine(row)}</strong>
                  </p>
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
