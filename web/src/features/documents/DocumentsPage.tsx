import { patientTabName } from '@/lib/openPatient';
import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  fetchDocuments,
  fetchPatientDocuments,
  uploadPatientDocument,
  downloadDocumentByUrl,
  type DocumentCategory,
  type DocumentItem,
} from '@/api/documents';
import { downloadAttachment } from '@/api/records';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Modal } from '@/components/ui/Modal';
import { Icon } from '@/components/Icon';
import { PatientPicker } from '@/components/PatientPicker';
import { downloadBlob } from '@/lib/download';
import { normalizeText } from '@/lib/string';
import { formatDate, t } from '@/i18n/es';
import type { Patient } from '@/api/types';

export type DocKind = 'prescription' | 'certificate' | 'order' | 'lab' | 'image' | 'other';

const KINDS: { key: DocKind; label: string; one: string; icon: string; tone: string }[] = [
  { key: 'prescription', label: 'Recetas', one: 'Receta', icon: 'pill', tone: 'red' },
  { key: 'certificate', label: 'Certificados', one: 'Certificado', icon: 'file', tone: 'amber' },
  { key: 'order', label: 'Órdenes', one: 'Orden', icon: 'steth', tone: 'blue' },
  { key: 'lab', label: 'Resultados', one: 'Resultado', icon: 'chart', tone: 'green' },
  { key: 'image', label: 'Imágenes', one: 'Imagen', icon: 'folder', tone: 'violet' },
  { key: 'other', label: 'Otros', one: 'Otro', icon: 'file', tone: 'muted' },
];
const KIND = Object.fromEntries(KINDS.map((k) => [k.key, k])) as Record<DocKind, (typeof KINDS)[number]>;

const UPLOAD_CATEGORIES: { key: DocumentCategory; label: string }[] = [
  { key: 'lab', label: 'Resultado de examen' },
  { key: 'image', label: 'Imagen o RX' },
  { key: 'prescription', label: 'Receta de otro médico' },
  { key: 'certificate', label: 'Certificado de otro médico' },
  { key: 'other', label: 'Otro' },
];

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];

type Period = 'month' | 'quarter' | 'all';
type Origin = 'all' | 'issued' | 'uploaded';

const PAGE = 60;

/**
 * What a document is. Documents Elosar issues know their kind; an upload
 * carries the type chosen when it was uploaded. Only older uploads are
 * inferred, and an unknown file is "Otro" — it used to be filed as a receta.
 */
export function kindOf(d: DocumentItem): DocKind {
  if (d.source === 'prescription') return 'prescription';
  if (d.source === 'certificate') return 'certificate';
  if (d.source === 'study_order') return 'order';

  const category = d.category ?? (d.type !== 'attachment' ? d.type : null);
  if (category && category in KIND) return category as DocKind;

  const ext = (d.extension ?? (d.title ?? '').split('.').pop() ?? '').toLowerCase();
  const hay = normalizeText(`${d.title ?? ''} ${d.notes ?? ''}`);
  if (IMAGE_EXT.includes(ext) || ['heic', 'dcm', 'dicom'].includes(ext) || /\b(rx|rayos|ecograf|tomograf|resonanc)/.test(hay)) return 'image';
  if (/(laborator|examen|resultado|hemograma|biometr|quimica|sangre|orina)/.test(hay)) return 'lab';
  return 'other';
}

/** "Hoy", "Esta semana", "octubre 2026"… — the list is read by when. */
function bucketOf(iso: string | null, now: Date): string {
  if (!iso) return 'Sin fecha';
  const d = new Date(iso);
  const day = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const diff = Math.floor((day.getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86_400_000);
  if (diff <= 0) return 'Hoy';
  if (diff === 1) return 'Ayer';
  if (diff < 7) return 'Esta semana';
  const label = d.toLocaleDateString('es-EC', { month: 'long', year: 'numeric' }).replace(' de ', ' ');
  return label.charAt(0).toUpperCase() + label.slice(1);
}

function since(period: Period, now: Date): number {
  if (period === 'month') return new Date(now.getFullYear(), now.getMonth(), 1).getTime();
  if (period === 'quarter') return now.getTime() - 90 * 86_400_000;
  return 0;
}

async function fetchBlob(d: DocumentItem): Promise<Blob> {
  return d.download_url ? downloadDocumentByUrl(d.download_url) : downloadAttachment(d.numeric_id ?? Number(d.id));
}

function fileName(d: DocumentItem): string {
  const base = d.title ?? `documento-${d.id}`;
  return d.extension && !base.toLowerCase().endsWith(`.${d.extension}`) ? `${base}.${d.extension}` : base;
}

/**
 * Documentos: every receta, certificate, order and uploaded file of the
 * workspace on one screen — search and filters on top, a compact list grouped
 * by date, and the document itself beside it. Replaces the "notebooks" (one big
 * tile per type and per patient) that took two clicks to reach a file and
 * could not be searched as a whole.
 */
export function DocumentsPage() {
  return <DocumentsBrowser />;
}

/**
 * The documents screen. With `patient` it is that patient's expediente tab:
 * the same list, filters and preview as the workspace screen, scoped to them,
 * and uploads go straight to them (no patient picker).
 */
export function DocumentsBrowser({ patient }: { patient?: Pick<Patient, 'id' | 'name'> }) {
  const { can } = useWorkspaceCapabilities();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const [query, setQuery] = useState('');
  const [kind, setKind] = useState<DocKind | 'all'>('all');
  const [period, setPeriod] = useState<Period>('all');
  const [origin, setOrigin] = useState<Origin>('all');
  const [limit, setLimit] = useState(PAGE);
  const [selectedId, setSelectedId] = useState<string | number | null>(null);
  const [showUpload, setShowUpload] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: patient ? ['patient-documents', activeWorkspaceId, patient.id] : ['documents', activeWorkspaceId],
    queryFn: () => (patient ? fetchPatientDocuments(patient.id) : fetchDocuments()),
  });

  const now = useMemo(() => new Date(), []);
  const docs = useMemo(() => (data ?? []).map((d) => ({ ...d, _kind: kindOf(d) })), [data]);

  // Counts follow every filter but the type, so each chip says what it would show.
  const beforeKind = useMemo(() => {
    const from = since(period, now);
    const q = normalizeText(query.trim());
    return docs.filter((d) => {
      if (origin === 'issued' && d.source === 'attachment') return false;
      if (origin === 'uploaded' && d.source !== 'attachment') return false;
      if (from && (!d.record_date || new Date(d.record_date).getTime() < from)) return false;
      if (q && !normalizeText(`${d.title ?? ''} ${d.patient?.name ?? ''} ${d.patient?.id_number ?? ''} ${d.notes ?? ''}`).includes(q)) return false;
      return true;
    });
  }, [docs, origin, period, query, now]);

  const counts = useMemo(() => {
    const out = Object.fromEntries(KINDS.map((k) => [k.key, 0])) as Record<DocKind, number>;
    beforeKind.forEach((d) => { out[d._kind] += 1; });
    return out;
  }, [beforeKind]);

  const filtered = useMemo(() => (kind === 'all' ? beforeKind : beforeKind.filter((d) => d._kind === kind)), [beforeKind, kind]);
  const visible = filtered.slice(0, limit);

  const groups = useMemo(() => {
    const out: { label: string; items: typeof visible }[] = [];
    visible.forEach((d) => {
      const label = bucketOf(d.record_date, now);
      const last = out.at(-1);
      if (last?.label === label) last.items.push(d);
      else out.push({ label, items: [d] });
    });
    return out;
  }, [visible, now]);

  // Keep a selection only while it is on screen; otherwise show the first one.
  const selected = filtered.find((d) => d.id === selectedId) ?? filtered[0] ?? null;

  useEffect(() => setLimit(PAGE), [query, kind, period, origin]);

  const download = async (d: DocumentItem) => {
    try {
      downloadBlob(await fetchBlob(d), fileName(d));
    } catch {
      toast('No se pudo descargar el documento.', 'error');
    }
  };

  if (isLoading) return <Spinner label={t.common.loading} />;

  const hasFilters = query.trim() !== '' || kind !== 'all' || period !== 'all' || origin !== 'all';

  return (
    <>
      {showUpload && <UploadDocumentModal patient={patient} onClose={() => setShowUpload(false)} />}

      {!patient && (
        <div className="page-head">
          <div>
            <h1>Documentos</h1>
            <p>{docs.length} documento{docs.length === 1 ? '' : 's'}: recetas, certificados, órdenes y archivos subidos</p>
          </div>
          {can('records.manage') && (
            <button className="btn primary sm" type="button" onClick={() => setShowUpload(true)}>
              <Icon name="upload" size={14} /> Subir documento
            </button>
          )}
        </div>
      )}

      <div className="docs-toolbar">
        <div className="search docs-search">
          <Icon name="search" />
          <input
            aria-label="Buscar documentos"
            placeholder={patient ? 'Buscar por título o nota…' : 'Buscar por paciente, cédula o título…'}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <select className="field docs-select" aria-label="Período" value={period} onChange={(e) => setPeriod(e.target.value as Period)}>
          <option value="all">Todas las fechas</option>
          <option value="quarter">Últimos 3 meses</option>
          <option value="month">Este mes</option>
        </select>
        <select className="field docs-select" aria-label="Origen" value={origin} onChange={(e) => setOrigin(e.target.value as Origin)}>
          <option value="all">Emitidos y subidos</option>
          <option value="issued">Emitidos en Elosar</option>
          <option value="uploaded">Archivos subidos</option>
        </select>
        {patient && can('records.manage') && (
          <button className="btn primary sm" type="button" onClick={() => setShowUpload(true)}>
            <Icon name="upload" size={14} /> Subir documento
          </button>
        )}
      </div>

      <div className="docs-kinds" role="radiogroup" aria-label="Tipo de documento">
        <button type="button" role="radio" aria-checked={kind === 'all'}
          className={`docs-kind ${kind === 'all' ? 'active' : ''}`} onClick={() => setKind('all')}>
          Todos <b>{beforeKind.length}</b>
        </button>
        {KINDS.map((k) => (
          <button key={k.key} type="button" role="radio" aria-checked={kind === k.key}
            disabled={counts[k.key] === 0 && kind !== k.key}
            className={`docs-kind tone-${k.tone} ${kind === k.key ? 'active' : ''}`} onClick={() => setKind(k.key)}>
            <Icon name={k.icon} size={14} /> {k.label} <b>{counts[k.key]}</b>
          </button>
        ))}
      </div>

      {docs.length === 0 ? (
        <Card>
          <p className="docs-empty">
            Aún no hay documentos. Aquí se juntan solas las recetas, certificados y órdenes que emites, y los archivos
            que subas a cada paciente.
          </p>
          <button className="btn primary sm" type="button" onClick={() => setShowUpload(true)}>
            <Icon name="upload" size={14} /> Subir el primero
          </button>
        </Card>
      ) : (
        <div className="split-view docs-workspace">
          <Card padded={false}>
            {filtered.length === 0 ? (
              <div className="card-pad">
                <p className="docs-empty">
                  Nada coincide con la búsqueda.{' '}
                  {hasFilters && (
                    <button type="button" className="link-btn" onClick={() => { setQuery(''); setKind('all'); setPeriod('all'); setOrigin('all'); }}>
                      Quitar filtros
                    </button>
                  )}
                </p>
              </div>
            ) : (
              <div className="docs-list" role="list" aria-label="Documentos">
                {groups.map((g) => (
                  <div key={g.label} role="presentation">
                    <div className="docs-group">{g.label}</div>
                    {g.items.map((d) => {
                      const meta = KIND[d._kind];
                      const active = selected?.id === d.id;
                      return (
                        <div key={d.id} role="listitem" className={`docs-row ${active ? 'active' : ''}`}>
                          <button type="button" className="docs-row-main" aria-current={active || undefined} onClick={() => setSelectedId(d.id)}>
                            <span className={`docs-ic tone-${meta.tone}`}><Icon name={meta.icon} size={15} /></span>
                            <span className="docs-row-text">
                              <b title={d.title ?? undefined}>{d.title ?? `Documento ${d.id}`}</b>
                              <span>{patient ? '' : `${d.patient?.name ?? 'Sin paciente'} · `}{meta.one}{d.source === 'attachment' ? ' · archivo subido' : ''}</span>
                            </span>
                            <span className="docs-row-date mono">{d.record_date ? formatDate(d.record_date) : '—'}</span>
                          </button>
                          <button type="button" className="icon-btn" aria-label={`Descargar ${d.title ?? 'documento'}`} onClick={() => download(d)}>
                            <Icon name="download" size={15} />
                          </button>
                        </div>
                      );
                    })}
                  </div>
                ))}
                {filtered.length > limit && (
                  <div className="docs-more">
                    <button type="button" className="btn sm ghost" onClick={() => setLimit((n) => n + PAGE)}>
                      Mostrar más ({filtered.length - limit} restantes)
                    </button>
                  </div>
                )}
              </div>
            )}
          </Card>

          <aside className="split-aside docs-aside" aria-label="Documento seleccionado">
            {selected ? (
              <DocumentPreview doc={selected} kind={selected._kind} onDownload={() => download(selected)} showPatient={!patient} />
            ) : (
              <Card><p className="docs-empty">Elige un documento para verlo aquí.</p></Card>
            )}
          </aside>
        </div>
      )}
    </>
  );
}

function DocumentPreview({ doc, kind, onDownload, showPatient = true }: { doc: DocumentItem; kind: DocKind; onDownload: () => void; showPatient?: boolean }) {
  const meta = KIND[kind];
  const ext = (doc.extension ?? '').toLowerCase();
  const previewable = ext === 'pdf' || IMAGE_EXT.includes(ext);

  const blob = useQuery({
    queryKey: ['document-preview', doc.id],
    queryFn: () => fetchBlob(doc),
    enabled: previewable,
    staleTime: Infinity,
    retry: false,
  });
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!blob.data) { setUrl(null); return; }
    const next = URL.createObjectURL(blob.data);
    setUrl(next);
    return () => URL.revokeObjectURL(next);
  }, [blob.data]);

  return (
    <Card padded={false} className="docs-preview">
      <div className="docs-preview-head">
        <span className={`docs-ic tone-${meta.tone}`}><Icon name={meta.icon} size={16} /></span>
        <div className="docs-preview-title">
          <b>{doc.title ?? `Documento ${doc.id}`}</b>
          <span>{meta.one} · {doc.source === 'attachment' ? 'archivo subido' : 'emitido en Elosar'} · {doc.record_date ? formatDate(doc.record_date) : 'sin fecha'}</span>
        </div>
      </div>

      <div className="docs-frame">
        {!previewable ? (
          <p className="docs-frame-empty">Este archivo no tiene vista previa. Descárgalo para abrirlo.</p>
        ) : blob.isError ? (
          <p className="docs-frame-empty">No se pudo cargar la vista previa.</p>
        ) : !url ? (
          <p className="docs-frame-empty">Cargando…</p>
        ) : ext === 'pdf' ? (
          <iframe src={`${url}#toolbar=0&navpanes=0&view=FitH`} title={`Vista previa: ${doc.title ?? 'documento'}`} />
        ) : (
          <img src={url} alt={doc.title ?? 'Documento'} />
        )}
      </div>

      {doc.notes && <p className="docs-notes">{doc.notes}</p>}

      <div className="docs-preview-actions">
        <button type="button" className="btn sm primary" onClick={onDownload}>
          <Icon name="download" size={14} /> Descargar
        </button>
        {showPatient && doc.patient && (
          <Link className="btn sm" to={`/pacientes/${doc.patient.id}/documentos`} target={patientTabName(doc.patient.id)}>
            <Icon name="user" size={14} /> {doc.patient.name}
          </Link>
        )}
      </div>
    </Card>
  );
}

function UploadDocumentModal({ onClose, patient: fixedPatient }: { onClose: () => void; patient?: Pick<Patient, 'id' | 'name'> }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const fileRef = useRef<HTMLInputElement>(null);
  const [picked, setPatient] = useState<Patient | null>(null);
  const patient = fixedPatient ?? picked;
  const [file, setFile] = useState<File | null>(null);
  const [category, setCategory] = useState<DocumentCategory>('lab');
  const [title, setTitle] = useState('');
  const [notes, setNotes] = useState('');
  const [dragging, setDragging] = useState(false);

  const upload = useMutation({
    mutationFn: () => uploadPatientDocument(patient!.id, file!, title, notes, category),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['documents', activeWorkspaceId] });
      void qc.invalidateQueries({ queryKey: ['patient-documents', activeWorkspaceId] });
      toast('Documento subido.', 'success');
      onClose();
    },
    onError: () => toast('No se pudo subir el documento.', 'error'),
  });

  const onDrop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setDragging(false);
    const dropped = e.dataTransfer.files?.[0];
    if (dropped) setFile(dropped);
  };

  return (
    <Modal title={fixedPatient ? `Subir documento · ${fixedPatient.name}` : 'Subir documento a paciente'} onClose={onClose} pending={upload.isPending}>
      <form className="stack" onSubmit={(e) => { e.preventDefault(); if (patient && file) upload.mutate(); }}>
        {!fixedPatient && <PatientPicker selected={picked} onSelect={setPatient} allowCreate={false} />}

        <div
          className={`docs-drop ${dragging ? 'dragging' : ''} ${file ? 'has-file' : ''}`}
          onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
          onDragLeave={() => setDragging(false)}
          onDrop={onDrop}
        >
          <Icon name="upload" size={18} />
          {file ? <b>{file.name}</b> : <span>Arrastra el archivo aquí o</span>}
          <button type="button" className="btn sm" onClick={() => fileRef.current?.click()}>
            {file ? 'Cambiar archivo' : 'Elegir archivo'}
          </button>
          <input
            ref={fileRef}
            id="document-file"
            aria-label="Archivo"
            type="file"
            hidden
            accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
          <span className="docs-drop-hint">PDF, imagen o Word, hasta 20 MB.</span>
        </div>

        <div className="form-grid">
          <div>
            <label className="field-label" htmlFor="document-category">Tipo</label>
            <select id="document-category" className="field" value={category} onChange={(e) => setCategory(e.target.value as DocumentCategory)}>
              {UPLOAD_CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
            </select>
          </div>
          <div>
            <label className="field-label" htmlFor="document-title">Título (opcional)</label>
            <input id="document-title" className="field" placeholder="Ej. Hemograma del laboratorio X"
              value={title} onChange={(e) => setTitle(e.target.value)} />
          </div>
          <div className="full">
            <label className="field-label" htmlFor="document-notes">Notas (opcional)</label>
            <textarea id="document-notes" className="field" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        </div>

        <div className="row docs-modal-actions">
          <button type="button" className="btn ghost sm" onClick={onClose} disabled={upload.isPending}>Cancelar</button>
          <button type="submit" className="btn primary sm" disabled={!patient || !file || upload.isPending}>
            {upload.isPending ? 'Subiendo…' : 'Subir documento'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
