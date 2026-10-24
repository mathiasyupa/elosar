import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { usePatientWorkspace } from './context';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { QueryError } from '@/components/ui/QueryError';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { PregnancyCard } from '@/features/obstetrics/PregnancyCard';
import { formatDate } from '@/i18n/es';
import {
  closePregnancy,
  createPregnancy,
  fetchGynecoHistory,
  fetchPregnancies,
  fetchScreenings,
  saveGynecoHistory,
  updatePregnancy,
} from '@/api/obstetrics';
import type {
  DatingSource,
  GynecoHistory,
  GynecoHistoryInput,
  Pregnancy,
  PregnancyOutcome,
} from '@/api/types';

const CONTRACEPTIVES: Record<string, string> = {
  none: 'Ninguno',
  combined_oral: 'Anticonceptivo oral combinado',
  progestin_only: 'Progestágeno solo',
  injectable: 'Inyectable',
  implant: 'Implante subdérmico',
  iud_copper: 'DIU de cobre',
  iud_hormonal: 'DIU hormonal',
  condom: 'Preservativo',
  tubal_ligation: 'Ligadura de trompas',
  vasectomy_partner: 'Vasectomía de la pareja',
  rhythm: 'Método del ritmo',
  other: 'Otro',
};

const OUTCOMES: Record<PregnancyOutcome, string> = {
  term_birth: 'Parto a término',
  preterm_birth: 'Parto pretérmino',
  cesarean: 'Cesárea',
  abortion: 'Aborto',
  ectopic: 'Embarazo ectópico',
  stillbirth: 'Óbito fetal',
  molar: 'Embarazo molar',
};

/** Los ocho campos de la fórmula obstétrica, en el orden en que se dictan. */
/** `short` is what fits the four-column grid of the ficha; `label` stays the
 *  accessible name. */
const FORMULA: { key: keyof GynecoHistoryInput; label: string; short: string }[] = [
  { key: 'gestations', label: 'Gestas', short: 'Gestas' },
  { key: 'term_births', label: 'Partos a término', short: 'A término' },
  { key: 'preterm_births', label: 'Partos pretérmino', short: 'Pretérmino' },
  { key: 'cesareans', label: 'Cesáreas', short: 'Cesáreas' },
  { key: 'abortions', label: 'Abortos', short: 'Abortos' },
  { key: 'ectopics', label: 'Ectópicos', short: 'Ectópicos' },
  { key: 'stillbirths', label: 'Óbitos', short: 'Óbitos' },
  { key: 'living_children', label: 'Hijos vivos', short: 'Hijos vivos' },
];

const num = (v: string) => (v === '' ? null : Number(v));
const nullableBoolean = (v: string) => (v === '' ? null : v === 'true');

/** Quiet time after the last keystroke before the ficha saves itself. An
 *  object, not a constant, so tests can shorten it. */
export const gynecoTiming = { autosaveDelayMs: 1500 };

/** Whole years since a YYYY-MM-DD date — "hace 3 años" beside a screening. */
function yearsSince(date: string): number | null {
  const d = new Date(`${date}T00:00:00`);
  if (Number.isNaN(d.getTime())) return null;
  const now = new Date();
  let years = now.getFullYear() - d.getFullYear();
  if (now.getMonth() < d.getMonth() || (now.getMonth() === d.getMonth() && now.getDate() < d.getDate())) years -= 1;
  return Math.max(0, years);
}

function agoLabel(date: string): string {
  const years = yearsSince(date);
  if (years == null) return '';
  return years === 0 ? 'este año' : years === 1 ? 'hace 1 año' : `hace ${years} años`;
}

function formFromHistory(d: GynecoHistory): Record<string, string> {
  return {
    menarche_age: d.menarche_age?.toString() ?? '',
    cycle_length_days: d.cycle_length_days?.toString() ?? '',
    cycle_duration_days: d.cycle_duration_days?.toString() ?? '',
    cycle_is_regular: d.cycle_is_regular == null ? '' : d.cycle_is_regular ? 'true' : 'false',
    dysmenorrhea: d.dysmenorrhea == null ? '' : d.dysmenorrhea ? 'true' : 'false',
    sexual_debut_age: d.sexual_debut_age?.toString() ?? '',
    contraceptive_method: d.contraceptive_method ?? '',
    contraceptive_notes: d.contraceptive_notes ?? '',
    last_pap_at: d.last_pap_at ?? '',
    last_pap_result: d.last_pap_result ?? '',
    last_mammography_at: d.last_mammography_at ?? '',
    last_mammography_result: d.last_mammography_result ?? '',
    is_menopausal: d.is_menopausal == null ? '' : d.is_menopausal ? 'true' : 'false',
    menopause_at: d.menopause_at ?? '',
    notes: d.notes ?? '',
    ...Object.fromEntries(
      FORMULA.map((f) => [f.key, (d.declared[f.key as keyof typeof d.declared] ?? '').toString()]),
    ),
  };
}

function historyInput(form: Record<string, string>): GynecoHistoryInput {
  return {
    menarche_age: num(form.menarche_age ?? ''),
    cycle_length_days: num(form.cycle_length_days ?? ''),
    cycle_duration_days: num(form.cycle_duration_days ?? ''),
    cycle_is_regular: nullableBoolean(form.cycle_is_regular ?? ''),
    dysmenorrhea: nullableBoolean(form.dysmenorrhea ?? ''),
    sexual_debut_age: num(form.sexual_debut_age ?? ''),
    contraceptive_method: form.contraceptive_method || null,
    contraceptive_notes: form.contraceptive_notes || null,
    last_pap_at: form.last_pap_at || null,
    last_pap_result: form.last_pap_result || null,
    last_mammography_at: form.last_mammography_at || null,
    last_mammography_result: form.last_mammography_result || null,
    is_menopausal: nullableBoolean(form.is_menopausal ?? ''),
    menopause_at: form.menopause_at || null,
    notes: form.notes || null,
    ...Object.fromEntries(FORMULA.map((f) => [f.key, num(form[f.key as string] ?? '')])),
  } as GynecoHistoryInput;
}

/**
 * La ficha gíneco-obstétrica de la paciente, en una sola pantalla — como la
 * atención: a la izquierda su ficha (que persiste y se guarda sola), al centro
 * el embarazo en curso con su curva y su carnet, a la derecha lo pendiente y lo
 * que se puede hacer, empezando por iniciar el control prenatal.
 *
 * Vive aquí y no dentro de la consulta a propósito: es historia de la mujer, no
 * de una visita. Ver `knowledge/decisions/ADR-009-el-embarazo-es-una-entidad.md`.
 */
export function PatientGynecoTab() {
  const { patientId } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const { can } = useWorkspaceCapabilities();
  const canEdit = can('records.manage');
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [form, setForm] = useState<Record<string, string>>({});
  const [loaded, setLoaded] = useState(false);
  const [newPregnancy, setNewPregnancy] = useState(false);
  const [editing, setEditing] = useState<Pregnancy | null>(null);
  const [closing, setClosing] = useState<Pregnancy | null>(null);
  const savedJson = useRef<string | null>(null);
  const [failedJson, setFailedJson] = useState<string | null>(null);
  const [savedAt, setSavedAt] = useState<Date | null>(null);

  const history = useQuery({
    queryKey: ['gyneco-history', activeWorkspaceId, patientId],
    queryFn: () => fetchGynecoHistory(patientId),
  });

  const pregnancies = useQuery({
    queryKey: ['pregnancies', activeWorkspaceId, patientId],
    queryFn: () => fetchPregnancies(patientId),
  });

  // Sembrar el formulario una sola vez, cuando llega la historia: re-sembrarlo en
  // cada render pisaría lo que el médico está escribiendo.
  if (history.data && !loaded) {
    const seeded = formFromHistory(history.data);
    savedJson.current = JSON.stringify(seeded);
    setForm(seeded);
    setLoaded(true);
  }

  const formJson = JSON.stringify(form);
  const historyDirty = loaded && canEdit && formJson !== savedJson.current;
  const historyFailed = failedJson !== null && failedJson === formJson;

  const saveHistory = useMutation({
    mutationFn: (sent: { json: string; input: GynecoHistoryInput }) => saveGynecoHistory(patientId, sent.input),
    onSuccess: (_data, sent) => {
      savedJson.current = sent.json;
      setFailedJson(null);
      setSavedAt(new Date());
      qc.invalidateQueries({ queryKey: ['gyneco-history', activeWorkspaceId, patientId] });
    },
    onError: (_error, sent) => {
      setFailedJson(sent.json);
      toast('No se pudo guardar la ficha gíneco-obstétrica.', 'error');
    },
  });

  const sendHistory = () => saveHistory.mutate({ json: formJson, input: historyInput(form) });

  // The ficha saves itself a moment after the doctor stops typing — there is
  // no "Guardar historia" to forget.
  const sendRef = useRef(sendHistory);
  sendRef.current = sendHistory;
  useEffect(() => {
    if (!historyDirty || historyFailed || saveHistory.isPending) return;
    const timer = window.setTimeout(() => sendRef.current(), gynecoTiming.autosaveDelayMs);
    return () => window.clearTimeout(timer);
  }, [formJson, historyDirty, historyFailed, saveHistory.isPending]);

  // Leaving the section unmounts it: send what the timer had not.
  const flushRef = useRef<() => void>(() => {});
  flushRef.current = () => {
    if (!historyDirty || saveHistory.isPending) return;
    const input = historyInput(form);
    void Promise.resolve().then(() => saveGynecoHistory(patientId, input)).catch(() => {});
  };
  useEffect(() => () => flushRef.current(), []);

  const invalidatePregnancies = () => {
    qc.invalidateQueries({ queryKey: ['pregnancies', activeWorkspaceId, patientId] });
    // La banda de la cabecera lee el embarazo desde el paciente.
    qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
  };

  const set = (key: string, value: string) => setForm((f) => ({ ...f, [key]: value }));

  if (history.isLoading || pregnancies.isLoading) return <Spinner label="Cargando…" />;
  if (history.isError || history.isPaused) {
    return (
      <QueryError
        error={history.error}
        area="la historia gíneco-obstétrica"
        onRetry={() => void history.refetch()}
        retrying={history.isFetching}
        paused={history.isPaused}
      />
    );
  }
  if (pregnancies.isError || pregnancies.isPaused) {
    return (
      <QueryError
        error={pregnancies.error}
        area="el seguimiento de embarazos"
        onRetry={() => void pregnancies.refetch()}
        retrying={pregnancies.isFetching}
        paused={pregnancies.isPaused}
      />
    );
  }

  const registered = history.data?.registered_in_elosar;
  const list = pregnancies.data ?? [];
  const activePregnancy = list.find((pregnancy) => pregnancy.status === 'active') ?? null;
  const pastPregnancies = list.filter((pregnancy) => pregnancy.status !== 'active');

  const statusLabel = saveHistory.isPending
    ? 'Guardando…'
    : historyFailed
      ? 'No se pudo guardar'
      : historyDirty
        ? 'Guardando en un momento…'
        : savedAt
          ? 'Guardada'
          : null;

  // `full` is the accessible name when the visible label is shortened to fit.
  const field = (key: string, label: string, type: 'number' | 'date' | 'text' = 'text', disabled = false, full?: string) => (
    <Input
      id={`gyn-${key}`}
      label={label}
      aria-label={full}
      type={type}
      value={form[key] ?? ''}
      disabled={!canEdit || disabled}
      onChange={(e) => set(key, e.target.value)}
    />
  );

  return (
    <div className="gyneco-workspace gyn-board">
      <section className="gyn-col gyn-ficha" aria-label="Ficha gíneco-obstétrica">
        <div className="gyn-col-head">
          <h3>Ficha gíneco-obstétrica</h3>
          {statusLabel && (
            <span className={`gyn-save ${historyFailed ? 'error' : ''}`.trim()} role="status">
              {statusLabel}
            </span>
          )}
          {historyFailed && (
            <button className="btn ghost sm" type="button" onClick={sendHistory}>Reintentar</button>
          )}
        </div>
        <p className="gyn-hint">De la paciente, no de una consulta. Se guarda sola.</p>

        <fieldset className="gyn-group">
          <legend>Fórmula obstétrica</legend>
          <p className="gyn-hint">Lo que la paciente refiere, incluidos embarazos anteriores a esta clínica.</p>
          <div className="gyn-formula">
            {FORMULA.map((f) => (
              <Input
                key={f.key as string}
                id={`gyn-${f.key as string}`}
                label={f.short}
                aria-label={f.label}
                title={f.label}
                type="number"
                min={0}
                value={form[f.key as string] ?? ''}
                disabled={!canEdit}
                onChange={(e) => set(f.key as string, e.target.value)}
              />
            ))}
          </div>
          {/* Lo declarado y lo registrado no coinciden, y está bien: una es la
              historia de la mujer y la otra es lo que este sistema presenció. El
              servidor nunca pisa la primera con la segunda. */}
          {registered && registered.gestations > 0 && (
            <p className="muted gyn-registered">
              <Icon name="chart" size={13} /> Registrados en Elosar:{' '}
              <b>{registered.gestations}</b> embarazo{registered.gestations === 1 ? '' : 's'}
              {registered.cesareans > 0 && `, ${registered.cesareans} cesárea(s)`}
              {registered.abortions > 0 && `, ${registered.abortions} aborto(s)`}.
            </p>
          )}
        </fieldset>

        <fieldset className="gyn-group">
          <legend>Planificación</legend>
          <div className="gyn-grid">
          <div className="field-group">
            <label className="field-label" htmlFor="gyn-method">Método de planificación</label>
            <select
              id="gyn-method"
              className="field"
              value={form.contraceptive_method ?? ''}
              disabled={!canEdit}
              onChange={(e) => set('contraceptive_method', e.target.value)}
            >
              <option value="">—</option>
              {Object.entries(CONTRACEPTIVES).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          {field('contraceptive_notes', 'Notas de planificación')}
          </div>
        </fieldset>

        <fieldset className="gyn-group">
          <legend>Tamizajes</legend>
          <div className="gyn-grid">
            {field('last_pap_at', 'Último PAP', 'date', false, 'Último Papanicolaou')}
            {field('last_pap_result', 'Resultado PAP', 'text', false, 'Resultado del Papanicolaou')}
            {field('last_mammography_at', 'Última mamografía', 'date')}
            {field('last_mammography_result', 'Resultado', 'text', false, 'Resultado de la mamografía')}
          </div>
        </fieldset>

        <fieldset className="gyn-group">
          <legend>Ciclo y antecedentes</legend>
          <div className="gyn-grid">
            {field('menarche_age', 'Menarquia (edad)', 'number')}
            {field('sexual_debut_age', 'IVSA (edad)', 'number')}
            {field('cycle_length_days', 'Ciclo: cada cuántos días', 'number')}
            {field('cycle_duration_days', 'Ciclo: cuántos días dura', 'number')}
            <SelectField
              id="gyn-cycle-regular"
              label="Ciclo regular"
              value={form.cycle_is_regular ?? ''}
              disabled={!canEdit}
              onChange={(value) => set('cycle_is_regular', value)}
            />
            <SelectField
              id="gyn-dysmenorrhea"
              label="Dismenorrea"
              value={form.dysmenorrhea ?? ''}
              disabled={!canEdit}
              onChange={(value) => set('dysmenorrhea', value)}
            />
          </div>
        </fieldset>

        <fieldset className="gyn-group">
          <legend>Menopausia</legend>
          <div className="gyn-grid">
            <SelectField
              id="gyn-menopause"
              label="Menopausia"
              value={form.is_menopausal ?? ''}
              disabled={!canEdit}
              onChange={(value) => set('is_menopausal', value)}
            />
            {field('menopause_at', 'Fecha de menopausia', 'date', form.is_menopausal !== 'true')}
          </div>
        </fieldset>

        <div className="field-group">
          <label className="field-label" htmlFor="gyn-notes">Notas</label>
          <textarea
            id="gyn-notes"
            className="field"
            rows={3}
            value={form.notes ?? ''}
            disabled={!canEdit}
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
      </section>

      <section className="gyn-col gyn-main" aria-label="Embarazo">
        {activePregnancy ? (
          <PregnancyCard
            pregnancy={activePregnancy}
            canEdit={canEdit}
            actions={false}
            onEdit={() => setEditing(activePregnancy)}
            onClose={() => setClosing(activePregnancy)}
          />
        ) : (
          <div className="card gyn-empty">
            <EmptyState
              icon="cal"
              message="Sin embarazo activo registrado."
              hint="Al registrar uno, la edad gestacional y la fecha probable de parto se calculan solas y aparecen mientras atiendes."
            />
            {canEdit && (
              <Button size="sm" onClick={() => setNewPregnancy(true)}>
                <Icon name="plus" size={14} /> Registrar embarazo
              </Button>
            )}
          </div>
        )}

        {pastPregnancies.length > 0 && (
          <section className="gyn-past" aria-label="Embarazos previos">
            <h3>Embarazos previos</h3>
            <p className="gyn-hint">Desenlaces que esta clínica registró.</p>
            {pastPregnancies.map((p) => (
              <PregnancyCard
                key={p.id}
                pregnancy={p}
                canEdit={canEdit}
                onEdit={() => setEditing(p)}
                onClose={() => setClosing(p)}
              />
            ))}
          </section>
        )}
      </section>

      <GynecoPending
        history={form}
        activePregnancy={activePregnancy}
        canEdit={canEdit}
        canAttend={canEdit}
        onStartControl={() =>
          navigate(`/pacientes/${patientId}/atencion?tipo=${activePregnancy ? 'prenatal' : 'gynecological'}`)
        }
        onNewPregnancy={() => setNewPregnancy(true)}
        onEditDating={() => activePregnancy && setEditing(activePregnancy)}
        onClosePregnancy={() => activePregnancy && setClosing(activePregnancy)}
      />

      {(newPregnancy || editing) && (
        <PregnancyFormModal
          pregnancy={editing}
          onClose={() => {
            setNewPregnancy(false);
            setEditing(null);
          }}
          onSaved={() => {
            invalidatePregnancies();
            setNewPregnancy(false);
            setEditing(null);
          }}
          patientId={patientId}
        />
      )}

      {closing && (
        <ClosePregnancyModal
          pregnancy={closing}
          onClose={() => setClosing(null)}
          onSaved={() => {
            invalidatePregnancies();
            setClosing(null);
          }}
        />
      )}
    </div>
  );
}

/**
 * Lo pendiente y lo que se puede hacer, al lado — el equivalente del resumen de
 * la atención. Los exámenes que faltan salen del carnet tal como lo devuelve el
 * servidor (solo los universales: los "según riesgo" no son un olvido); no se
 * deduce nada por edad gestacional que el carnet no diga.
 */
function GynecoPending({
  history,
  activePregnancy,
  canEdit,
  canAttend,
  onStartControl,
  onNewPregnancy,
  onEditDating,
  onClosePregnancy,
}: {
  history: Record<string, string>;
  activePregnancy: Pregnancy | null;
  canEdit: boolean;
  canAttend: boolean;
  onStartControl: () => void;
  onNewPregnancy: () => void;
  onEditDating: () => void;
  onClosePregnancy: () => void;
}) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  // Same key as the carnet inside PregnancyCard: one request, shared.
  const screenings = useQuery({
    queryKey: ['pregnancy-screenings', activeWorkspaceId, activePregnancy?.id],
    queryFn: () => fetchScreenings(activePregnancy!.id),
    enabled: !!activePregnancy,
  });
  const missing = (screenings.data ?? []).filter((s) => s.universal && !s.recorded);
  const shown = missing.slice(0, 5);

  return (
    <aside className="gyn-pending" aria-label="Pendientes y acciones">
      <h4>Pendientes</h4>

      {activePregnancy?.risk_notes && (
        <div className="alert amber gyn-risk">
          <span className="ai"><Icon name="alert" size={15} /></span>
          <div><b>Riesgo registrado</b><p>{activePregnancy.risk_notes}</p></div>
        </div>
      )}

      {activePregnancy && (
        <section className="gyn-pending-block" aria-label="Exámenes del carnet sin registrar">
          <h5>Carnet prenatal</h5>
          {screenings.isLoading ? (
            <p className="gyn-hint">Revisando el carnet…</p>
          ) : missing.length === 0 ? (
            <p className="gyn-ok"><Icon name="check" size={13} /> Exámenes universales registrados.</p>
          ) : (
            <>
              <p className="gyn-pending-count">{missing.length} {missing.length === 1 ? 'examen' : 'exámenes'} sin registrar</p>
              <ul>
                {shown.map((s) => (
                  <li key={s.code}>
                    {s.label}
                    <span className="gyn-hint"> · {s.when}</span>
                  </li>
                ))}
              </ul>
              {missing.length > shown.length && (
                <p className="gyn-hint">y {missing.length - shown.length} más en el carnet.</p>
              )}
            </>
          )}
        </section>
      )}

      <section className="gyn-pending-block" aria-label="Tamizajes de la paciente">
        <h5>Tamizajes</h5>
        <p className="gyn-pending-line">
          Papanicolaou:{' '}
          {history.last_pap_at ? <>{formatDate(history.last_pap_at)} <span className="gyn-hint">({agoLabel(history.last_pap_at)})</span></> : <span className="gyn-hint">sin registrar</span>}
        </p>
        <p className="gyn-pending-line">
          Mamografía:{' '}
          {history.last_mammography_at ? <>{formatDate(history.last_mammography_at)} <span className="gyn-hint">({agoLabel(history.last_mammography_at)})</span></> : <span className="gyn-hint">sin registrar</span>}
        </p>
      </section>

      <div className="gyn-pending-actions">
        {canAttend && (
          <button className="btn primary" type="button" onClick={onStartControl}>
            <Icon name="steth" size={15} /> {activePregnancy ? 'Iniciar control prenatal' : 'Iniciar consulta ginecológica'}
          </button>
        )}
        {canEdit && !activePregnancy && (
          <button className="btn" type="button" onClick={onNewPregnancy}>
            <Icon name="plus" size={14} /> Registrar embarazo
          </button>
        )}
        {canEdit && activePregnancy && (
          <>
            <button className="btn ghost sm" type="button" onClick={onEditDating}>
              <Icon name="edit" size={14} /> Editar datación
            </button>
            <button className="btn ghost sm" type="button" onClick={onClosePregnancy}>
              <Icon name="check" size={14} /> Registrar desenlace
            </button>
          </>
        )}
      </div>
    </aside>
  );
}

function SelectField({
  id,
  label,
  value,
  disabled,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  disabled: boolean;
  onChange: (value: string) => void;
}) {
  return (
    <div className="field-group">
      <label className="field-label" htmlFor={id}>{label}</label>
      <select id={id} className="field" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}>
        <option value="">Sin registrar</option>
        <option value="true">Sí</option>
        <option value="false">No</option>
      </select>
    </div>
  );
}

function PregnancyFormModal({
  pregnancy,
  patientId,
  onClose,
  onSaved,
}: {
  pregnancy: Pregnancy | null;
  patientId: number;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useUiStore((s) => s.toast);
  const [v, setV] = useState({
    lmp_date: pregnancy?.lmp_date ?? '',
    lmp_is_reliable:
      pregnancy?.lmp_is_reliable == null ? '' : pregnancy.lmp_is_reliable ? 'true' : 'false',
    ultrasound_date: pregnancy?.ultrasound_date ?? '',
    ultrasound_weeks: pregnancy?.ultrasound_weeks?.toString() ?? '',
    ultrasound_days: pregnancy?.ultrasound_days?.toString() ?? '',
    dating_source: pregnancy?.dating_source ?? 'lmp',
    fetus_count: pregnancy?.fetus_count?.toString() ?? '1',
    risk_notes: pregnancy?.risk_notes ?? '',
  });
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => {
      const payload = {
        lmp_date: v.lmp_date || null,
        lmp_is_reliable: nullableBoolean(v.lmp_is_reliable),
        ultrasound_date: v.ultrasound_date || null,
        ultrasound_weeks: v.ultrasound_weeks === '' ? null : Number(v.ultrasound_weeks),
        ultrasound_days: v.ultrasound_days === '' ? null : Number(v.ultrasound_days),
        dating_source: v.dating_source as 'lmp' | 'ultrasound',
        fetus_count: v.fetus_count === '' ? undefined : Number(v.fetus_count),
        risk_notes: v.risk_notes || null,
      };
      return pregnancy ? updatePregnancy(pregnancy.id, payload) : createPregnancy(patientId, payload);
    },
    onSuccess: () => {
      toast(pregnancy ? 'Datación actualizada.' : 'Embarazo registrado.', 'success');
      onSaved();
    },
    onError: (e: unknown) => {
      const message =
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'No se pudo guardar el embarazo.';
      setError(message);
    },
  });

  return (
    <Modal
      title={pregnancy ? 'Datación del embarazo' : 'Registrar embarazo'}
      description="La fecha probable de parto la calcula el servidor con la FUM o la ecografía."
      onClose={onClose}
      pending={save.isPending}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? 'Guardando…' : 'Guardar'}
          </Button>
        </>
      }
    >
      <div className="form-grid">
        <Input
          id="preg-lmp"
          label="FUM"
          type="date"
          value={v.lmp_date}
          onChange={(e) => setV({ ...v, lmp_date: e.target.value })}
        />
        <SelectField
          id="preg-lmp-reliable"
          label="FUM confiable"
          value={v.lmp_is_reliable}
          disabled={false}
          onChange={(value) => setV({ ...v, lmp_is_reliable: value })}
        />
        <Input
          id="preg-us-date"
          label="Fecha de la ecografía"
          type="date"
          value={v.ultrasound_date}
          onChange={(e) => setV({ ...v, ultrasound_date: e.target.value })}
        />
        <Input
          id="preg-us-weeks"
          label="Semanas por ecografía"
          type="number"
          value={v.ultrasound_weeks}
          onChange={(e) => setV({ ...v, ultrasound_weeks: e.target.value })}
        />
        <Input
          id="preg-us-days"
          label="Días por ecografía"
          type="number"
          value={v.ultrasound_days}
          onChange={(e) => setV({ ...v, ultrasound_days: e.target.value })}
        />
        <Input
          id="preg-fetus"
          label="Número de fetos"
          type="number"
          min="1"
          max="6"
          value={v.fetus_count}
          onChange={(e) => setV({ ...v, fetus_count: e.target.value })}
        />
        <div className="field-group full">
          <label className="field-label" htmlFor="preg-dating">
            ¿Cuál manda?
          </label>
          <select
            id="preg-dating"
            className="field"
            value={v.dating_source}
            onChange={(e) => setV({ ...v, dating_source: e.target.value as DatingSource })}
          >
            <option value="lmp">La FUM</option>
            <option value="ultrasound">La ecografía</option>
          </select>
          {/* La norma no publica un umbral de redatación, así que el sistema no
              elige por el médico. Ver knowledge/reference/CARNE_PERINATAL.md. */}
          <p className="field-hint">
            Si las dos están registradas, esta decide la edad gestacional y la fecha probable
            de parto. El sistema no elige por ti.
          </p>
        </div>
        <div className="field-group full">
          <label className="field-label" htmlFor="preg-risk">
            Riesgo (notas)
          </label>
          <textarea
            id="preg-risk"
            className="field"
            rows={2}
            value={v.risk_notes}
            onChange={(e) => setV({ ...v, risk_notes: e.target.value })}
          />
        </div>
      </div>
      {error && <p className="field-error mono" role="alert">{error}</p>}
    </Modal>
  );
}

/** Cerrar es lo que libera a la paciente para un embarazo nuevo. */
function ClosePregnancyModal({
  pregnancy,
  onClose,
  onSaved,
}: {
  pregnancy: Pregnancy;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useUiStore((s) => s.toast);
  const [outcome, setOutcome] = useState<PregnancyOutcome>('term_birth');
  const [date, setDate] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () =>
      closePregnancy(pregnancy.id, {
        outcome,
        outcome_date: date,
        outcome_notes: notes || null,
      }),
    onSuccess: () => {
      toast('Embarazo cerrado.', 'success');
      onSaved();
    },
    onError: (e: unknown) => {
      setError(
        (e as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          'No se pudo cerrar el embarazo.',
      );
    },
  });

  return (
    <Modal
      title="Desenlace del embarazo"
      description="Al cerrarlo, la paciente queda libre para registrar un embarazo nuevo."
      onClose={onClose}
      pending={save.isPending}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            Cancelar
          </Button>
          <Button disabled={save.isPending || !date} onClick={() => save.mutate()}>
            {save.isPending ? 'Guardando…' : 'Cerrar embarazo'}
          </Button>
        </>
      }
    >
      <div className="form-grid">
        <div className="field-group">
          <label className="field-label" htmlFor="preg-outcome">
            Desenlace
          </label>
          <select
            id="preg-outcome"
            className="field"
            value={outcome}
            onChange={(e) => setOutcome(e.target.value as PregnancyOutcome)}
          >
            {Object.entries(OUTCOMES).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
        </div>
        <Input
          id="preg-outcome-date"
          label="Fecha"
          type="date"
          required
          value={date}
          onChange={(e) => setDate(e.target.value)}
        />
        <div className="field-group full">
          <label className="field-label" htmlFor="preg-outcome-notes">
            Notas
          </label>
          <textarea
            id="preg-outcome-notes"
            className="field"
            rows={2}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </div>
      {error && <p className="field-error mono" role="alert">{error}</p>}
    </Modal>
  );
}
