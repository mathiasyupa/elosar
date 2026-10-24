import { useEffect, useMemo, useState } from 'react';
import { useHonorificGate } from '@/components/HonorificPrompt';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { fetchPatientRecords } from '@/api/patients';
import {
  CERTIFICATE_TYPES,
  CONTINGENCY_TYPES,
  createCertificate,
  downloadCertificatePdf,
  duplicateCertificate,
  updateCertificate,
  fetchPatientCertificates,
  previewCertificateDraft,
  previewCertificatePdf,
  type CertificateTypeKey,
} from '@/api/certificates';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { LetterheadNotice } from '@/components/LetterheadNotice';
import { downloadBlob } from '@/lib/download';
import { useLetterheadStatus } from '@/lib/useLetterheadStatus';
import { formatDate, t } from '@/i18n/es';
import { usePatientWorkspace } from './context';
import type { MedicalCertificate, MedicalCertificateInput, Patient } from '@/api/types';

const TYPE_KEYS = Object.keys(CERTIFICATE_TYPES) as CertificateTypeKey[];

/** YYYY-MM-DD in local time (toISOString() shifts the day in Ecuador). */
function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function defaultForm(type: CertificateTypeKey, patient?: Patient): MedicalCertificateInput {
  const preset = CERTIFICATE_TYPES[type];
  const base: MedicalCertificateInput = {
    type: preset.value,
    title: preset.title,
    body: preset.body,
    issued_at: today(),
    rest_days: type === 'rest' ? 3 : null,
    valid_from: type === 'rest' ? today() : null,
    valid_until: null,
    medical_record_id: null,
  };

  if (type !== 'medical') return base;

  // What the clinic already knows about the patient is filled in, so the doctor
  // only types what the certificate adds.
  return {
    ...base,
    city: patient?.city ?? '',
    hc_number: patient?.id_number ?? '',
    // Now that the patient carries an address, stop making the doctor retype it.
    address: patient?.address ?? '',
    phone: patient?.phone ?? '',
    contingency_type: CONTINGENCY_TYPES[0],
    company: '',
    activity: '',
    attended_at: today(),
    symptoms: '',
    diagnosis: '',
    cie10_codes: '',
    show_cie10: true,
    treatment: '',
    absolute_rest: true,
    valid_from: today(),
  };
}

export function PatientCertificatesTab({ embedded = false }: { embedded?: boolean } = {}) {
  const { patientId, patient } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const [showNew, setShowNew] = useState(false);
  const [editing, setEditing] = useState<MedicalCertificate | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [preview, setPreview] = useState<{ id: number; url: string } | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['patient-certificates', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientCertificates(patientId),
  });

  const certificates = data?.data ?? [];

  /** The PDF opens under its card, in the page — no new tab, no pop-up. */
  const handlePreview = async (id: number) => {
    if (preview) URL.revokeObjectURL(preview.url);
    if (preview?.id === id) {
      setPreview(null);
      return;
    }
    setBusyId(id);
    try {
      const blob = await previewCertificatePdf(id);
      setPreview({ id, url: URL.createObjectURL(blob) });
    } catch {
      toast('No se pudo abrir la vista previa.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  const handleDownload = async (id: number) => {
    await ensureHonorific();
    setBusyId(id);
    try {
      const blob = await downloadCertificatePdf(id);
      downloadBlob(blob, `certificado-${id}.pdf`);
      toast('Certificado descargado.', 'success');
    } catch {
      toast('No se pudo descargar el certificado.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  const handleDuplicate = async (id: number) => {
    setBusyId(id);
    try {
      await duplicateCertificate(id);
      qc.invalidateQueries({ queryKey: ['patient-certificates', activeWorkspaceId, patientId] });
      toast('Certificado duplicado — puedes editarlo emitiendo uno nuevo.', 'success');
    } catch {
      toast('No se pudo duplicar.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  if (isLoading) return <Spinner label={t.common.loading} />;

  return (
    <>
      <div className="row between" style={{ marginBottom: 14 }}>
        {!embedded && (
          <p className="muted" style={{ fontSize: 13, margin: 0 }}>
            Constancias, reposos y certificados con el membrete de tu consultorio.
          </p>
        )}
        {!showNew && (
          <button className="btn primary sm" onClick={() => setShowNew(true)} type="button">
            <Icon name="plus" size={14} /> Emitir certificado
          </button>
        )}
      </div>

      {(showNew || editing) && (
        <NewCertificateForm
          key={editing?.id ?? 'new'}
          patientId={patientId}
          patient={patient}
          editing={editing}
          onClose={() => { setShowNew(false); setEditing(null); }}
          onCreated={() => {
            setShowNew(false);
            setEditing(null);
            qc.invalidateQueries({ queryKey: ['patient-certificates', activeWorkspaceId, patientId] });
          }}
        />
      )}

      {certificates.length === 0 && showNew ? null : certificates.length === 0 ? (
        embedded ? (
          <Card><p className="muted">Sin certificados emitidos.</p></Card>
        ) : (
          <Card>
            <EmptyState
              icon="file"
              message="Este paciente no tiene certificados emitidos."
              hint="Constancias de atención, reposos y certificados de salud."
              action={
                <button className="btn primary sm" onClick={() => setShowNew(true)} type="button">
                  <Icon name="plus" size={14} /> Emitir certificado
                </button>
              }
            />
          </Card>
        )
      ) : (
        <div className="stack">
          {certificates.map((cert) => (
            <CertificateCard
              key={cert.id}
              cert={cert}
              busy={busyId === cert.id}
              onPreview={() => handlePreview(cert.id)}
              onDownload={() => handleDownload(cert.id)}
              onDuplicate={() => handleDuplicate(cert.id)}
              onEdit={() => { setShowNew(false); setEditing(cert); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
              previewUrl={preview?.id === cert.id ? preview.url : null}
            />
          ))}
        </div>
      )}

    </>
  );
}

function CertificateCard({
  cert,
  busy,
  onPreview,
  onDownload,
  onDuplicate,
  onEdit,
  previewUrl,
}: {
  cert: MedicalCertificate;
  busy: boolean;
  onPreview: () => void;
  onDownload: () => void;
  onDuplicate: () => void;
  onEdit: () => void;
  previewUrl: string | null;
}) {
  return (
    <Card padded={false}>
      <div className="card-head">
        <div>
          <h3>{cert.title}</h3>
          <span className="sub">
            {cert.type_label} · {formatDate(cert.issued_at)}
            {cert.doctor_name ? ` · ${cert.doctor_name}` : ''}
            {cert.rest_days ? ` · ${cert.rest_days} día(s) reposo` : ''}
          </span>
        </div>
        <div className="row" style={{ gap: 6 }}>
          <button className="btn sm" disabled={busy} onClick={onPreview} type="button" aria-expanded={previewUrl != null}>
            {previewUrl ? 'Ocultar vista previa' : 'Vista previa'}
          </button>
          <button className="btn sm" disabled={busy} onClick={onEdit} type="button">
            <Icon name="edit" size={14} /> Editar
          </button>
          <button className="btn sm" disabled={busy} onClick={onDuplicate} type="button">
            Duplicar
          </button>
          <button className="btn primary sm" disabled={busy} onClick={onDownload} type="button">
            <Icon name="download" size={15} />
            {busy ? '…' : 'PDF'}
          </button>
        </div>
      </div>
      <div className="card-pad" style={{ paddingTop: 0 }}>
        <p style={{ fontSize: 13, lineHeight: 1.55, whiteSpace: 'pre-line', margin: 0 }}>{cert.body}</p>
      </div>
      {previewUrl && <iframe className="cert-preview" src={previewUrl} title={`Vista previa: ${cert.title}`} />}
    </Card>
  );
}

/** What an issued certificate holds, back as form values to edit it. */
function formFromCertificate(cert: MedicalCertificate): MedicalCertificateInput {
  const { id: _id, patient_id: _p, type_label: _l, created_at: _c, doctor_name: _d, ...rest } = cert as MedicalCertificate & { doctor_name?: string };
  return { ...rest, issued_at: cert.issued_at?.slice(0, 10) ?? today(), show_cie10: cert.show_cie10 ?? true } as MedicalCertificateInput;
}

function NewCertificateForm({
  patientId,
  patient,
  editing,
  onClose,
  onCreated,
}: {
  patientId: number;
  patient: Patient;
  /** An issued certificate to fix instead of issuing a new one. */
  editing?: MedicalCertificate | null;
  onClose: () => void;
  onCreated: () => void;
}) {
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [typeKey, setTypeKey] = useState<CertificateTypeKey>(() => (editing?.type as CertificateTypeKey) ?? 'medical');
  const [form, setForm] = useState<MedicalCertificateInput>(() => (editing ? formFromCertificate(editing) : defaultForm('medical', patient)));

  const { data: records } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
  });

  const recordOptions = useMemo(() => records?.data ?? [], [records]);

  const mutation = useMutation({
    mutationFn: (payload: MedicalCertificateInput) => (editing ? updateCertificate(editing.id, payload) : createCertificate(patientId, payload)),
    onSuccess: () => {
      toast(editing ? 'Certificado actualizado.' : 'Certificado emitido.', 'success');
      onCreated();
    },
    onError: (error) =>
      toast(
        (isAxiosError(error) && (error.response?.data as { message?: string })?.message) ||
          (editing ? 'No se pudo guardar el certificado.' : 'No se pudo emitir el certificado.'),
        'error',
      ),
  });

  const pickType = (key: CertificateTypeKey) => {
    setTypeKey(key);
    setForm(defaultForm(key, patient));
  };

  const isParameterised = typeKey === 'medical';
  const { configured: hasLetterhead } = useLetterheadStatus();

  const update = <K extends keyof MedicalCertificateInput>(key: K, value: MedicalCertificateInput[K]) => {
    setForm((f) => ({ ...f, [key]: value }));
  };

  const apiError = mutation.isError
    ? (isAxiosError(mutation.error) && (mutation.error.response?.data as { message?: string })?.message) ||
      'Error al guardar.'
    : null;

  return (
    <Card className="cert-new">
      <div className="card-head flush">
        <h3>{editing ? 'Editar certificado' : 'Emitir certificado médico'}</h3>
      </div>
      <div className="cert-new-grid">
      <div className="cert-new-form">
      {!editing && (
      <div className="row wrap" style={{ gap: 8, marginBottom: 16 }}>
        {TYPE_KEYS.map((key) => (
          <button
            key={key}
            className={`btn sm ${typeKey === key ? 'primary' : ''}`.trim()}
            onClick={() => pickType(key)}
            type="button"
          >
            {CERTIFICATE_TYPES[key].label}
          </button>
        ))}
      </div>
      )}

      <div className="form-grid">
        <div className="full">
          <label className="field-label">Título del documento *</label>
          <input className="field" value={form.title} onChange={(e) => update('title', e.target.value)} />
        </div>
        <div>
          <label className="field-label">Fecha de emisión</label>
          <input className="field" type="date" value={form.issued_at ?? ''} onChange={(e) => update('issued_at', e.target.value)} />
        </div>
        <div>
          <label className="field-label">Vincular a consulta</label>
          <select
            className="field"
            value={form.medical_record_id ?? ''}
            onChange={(e) => update('medical_record_id', e.target.value ? Number(e.target.value) : null)}
          >
            <option value="">— Ninguna —</option>
            {recordOptions.map((r) => (
              <option key={r.id} value={r.id}>
                {formatDate(r.created_at)} — {r.reason_for_consultation ?? 'Consulta'}
              </option>
            ))}
          </select>
        </div>
        {typeKey === 'rest' && (
          <>
            <div>
              <label className="field-label">Días de reposo</label>
              <input
                className="field"
                inputMode="numeric"
                value={form.rest_days ?? ''}
                onChange={(e) => update('rest_days', e.target.value ? Number(e.target.value) : null)}
              />
            </div>
            <div>
              <label className="field-label">Desde</label>
              <input className="field" type="date" value={form.valid_from ?? ''} onChange={(e) => update('valid_from', e.target.value)} />
            </div>
            <div>
              <label className="field-label">Hasta</label>
              <input className="field" type="date" value={form.valid_until ?? ''} onChange={(e) => update('valid_until', e.target.value)} />
            </div>
          </>
        )}

        {isParameterised ? (
          <>
            <div>
              <label className="field-label">Ciudad</label>
              <input className="field" value={form.city ?? ''} onChange={(e) => update('city', e.target.value)} />
            </div>
            <div>
              <label className="field-label">Historia clínica</label>
              <input className="field mono" value={form.hc_number ?? ''} onChange={(e) => update('hc_number', e.target.value)} />
            </div>
            <div className="full">
              <label className="field-label">Dirección</label>
              <input
                className="field"
                value={form.address ?? ''}
                placeholder="Barrio, calle principal y secundaria, número"
                onChange={(e) => update('address', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Teléfono</label>
              <input className="field" value={form.phone ?? ''} onChange={(e) => update('phone', e.target.value)} />
            </div>
            <div>
              <label className="field-label">Tipo de contingencia</label>
              <select
                className="field"
                value={form.contingency_type ?? ''}
                onChange={(e) => update('contingency_type', e.target.value)}
              >
                {CONTINGENCY_TYPES.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="field-label">Empresa</label>
              <input
                className="field"
                value={form.company ?? ''}
                placeholder="Dónde trabaja o estudia"
                onChange={(e) => update('company', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Actividad</label>
              <input
                className="field"
                value={form.activity ?? ''}
                placeholder="Cargo o actividad que realiza"
                onChange={(e) => update('activity', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Fecha de atención</label>
              <input
                className="field"
                type="date"
                value={form.attended_at ?? ''}
                onChange={(e) => update('attended_at', e.target.value)}
              />
            </div>
            <div className="full">
              <label className="field-label">Síntomas</label>
              <textarea
                className="field"
                rows={2}
                value={form.symptoms ?? ''}
                placeholder="Cefalea, dolor cervical, dolor lumbar…"
                onChange={(e) => update('symptoms', e.target.value)}
              />
            </div>
            <div className="full">
              <label className="field-label">Diagnóstico de atención</label>
              <textarea
                className="field"
                rows={2}
                value={form.diagnosis ?? ''}
                onChange={(e) => update('diagnosis', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">CIE 10</label>
              <input
                className="field mono"
                value={form.cie10_codes ?? ''}
                placeholder="S007 / S307"
                onChange={(e) => update('cie10_codes', e.target.value)}
              />
              {/* Some patients do not want their diagnosis code on a note for
                  their employer: the doctor chooses, and the preview follows. */}
              <label className="cert-cie10-toggle">
                <input
                  type="checkbox"
                  checked={form.show_cie10 ?? true}
                  onChange={(e) => update('show_cie10', e.target.checked)}
                />
                Incluir el CIE-10 en el certificado
              </label>
              {!(form.show_cie10 ?? true) && <p className="cert-cie10-hint">No se imprimirá en el certificado.</p>}
            </div>
            <div>
              <label className="field-label">Tratamiento</label>
              <input
                className="field"
                value={form.treatment ?? ''}
                placeholder="Sintomático"
                onChange={(e) => update('treatment', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Reposo absoluto</label>
              <select
                className="field"
                value={form.absolute_rest === null || form.absolute_rest === undefined ? '' : form.absolute_rest ? 'si' : 'no'}
                onChange={(e) => update('absolute_rest', e.target.value === '' ? null : e.target.value === 'si')}
              >
                <option value="si">Sí</option>
                <option value="no">No</option>
                <option value="">No indicar</option>
              </select>
            </div>
            <div>
              <label className="field-label">Reposo desde</label>
              <input
                className="field"
                type="date"
                value={form.valid_from ?? ''}
                onChange={(e) => update('valid_from', e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Reposo hasta el</label>
              <input
                className="field"
                type="date"
                value={form.valid_until ?? ''}
                onChange={(e) => update('valid_until', e.target.value)}
              />
            </div>
            <div className="full">
              <label className="field-label">Observación adicional (opcional)</label>
              <textarea
                className="field"
                rows={2}
                value={form.body}
                placeholder="Se imprime como un párrafo más, antes del cierre."
                onChange={(e) => update('body', e.target.value)}
              />
            </div>
          </>
        ) : (
          <div className="full">
            <label className="field-label">Contenido del certificado *</label>
            <textarea className="field" rows={6} value={form.body} onChange={(e) => update('body', e.target.value)} />
          </div>
        )}
        {apiError && <p className="field-error full">{apiError}</p>}
      </div>

      {/* The note below promises a letterhead. When there is none, the promise
          becomes a warning instead of a lie — right above "Emitir certificado". */}
      <LetterheadNotice className="alert-spaced" />

      {(isParameterised || hasLetterhead) && (
        <p className="muted" style={{ fontSize: 12, marginTop: 12 }}>
          {isParameterised
            ? 'Las fechas se imprimen en números y en letras, como exige el empleador o el IESS.'
            : 'El PDF usará el logo y membrete configurados en Ajustes → Papelería de documentos, y tu firma de Perfil.'}
        </p>
      )}

      <div className="cert-new-actions">
        <button className="btn" onClick={onClose} type="button">
          Cancelar
        </button>
        <button
          className="btn primary"
          disabled={!form.title.trim() || (!isParameterised && !form.body.trim()) || mutation.isPending}
          onClick={async () => { await ensureHonorific(); mutation.mutate(form); }}
          type="button"
        >
          {mutation.isPending ? (editing ? 'Guardando…' : 'Emitiendo…') : editing ? 'Guardar cambios' : 'Emitir certificado'}
        </button>
      </div>
      </div>
      <DraftPreview patientId={patientId} form={form} ready={!!form.title.trim() && (isParameterised || !!form.body.trim())} />
      </div>
    </Card>
  );
}

/**
 * The real PDF of the certificate being written, beside the form. It follows
 * the typing with a short pause, so the doctor sees what will be printed
 * without a pop-up or a new tab. Nothing is saved until «Emitir certificado».
 */
function DraftPreview({ patientId, form, ready }: { patientId: number; form: MedicalCertificateInput; ready: boolean }) {
  const [url, setUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const payload = JSON.stringify(form);

  useEffect(() => {
    if (!ready) return;
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      setLoading(true);
      try {
        const blob = await previewCertificateDraft(patientId, JSON.parse(payload) as MedicalCertificateInput);
        if (cancelled) return;
        setUrl((prev) => {
          if (prev) URL.revokeObjectURL(prev);
          return URL.createObjectURL(blob);
        });
      } catch {
        /* an incomplete form simply keeps the last good preview */
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, 700);
    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [patientId, payload, ready]);

  useEffect(() => () => {
    if (url) URL.revokeObjectURL(url);
  }, [url]);

  return (
    <aside className="cert-live" aria-label="Vista previa del certificado" aria-busy={loading}>
      <div className="cert-live-head">
        <span>Vista previa</span>
        {loading && <span className="muted">Actualizando…</span>}
      </div>
      {url ? (
        <iframe className="cert-live-frame" src={`${url}#toolbar=0&navpanes=0&view=FitH`} title="Vista previa del certificado" />
      ) : (
        <p className="cert-live-empty">{ready ? 'Preparando la vista previa…' : 'Escribe el título y el contenido para ver el certificado.'}</p>
      )}
    </aside>
  );
}
