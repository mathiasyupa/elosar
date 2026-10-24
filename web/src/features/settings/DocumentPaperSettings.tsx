import { useEffect, useRef, useState, type ChangeEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import {
  DEFAULT_SHEET_MARGINS,
  deleteHeaderImage,
  deleteLogo,
  deleteSheetImage,
  previewDocumentPdf,
  readPrescriptionSettings,
  updateLetterhead,
  uploadHeaderImage,
  uploadLogo,
  uploadSheetImage,
  type DocumentKind,
  type Paper,
  type PaperByDocument,
  type SheetMargins,
} from '@/api/settings';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import type { PrescriptionSettings, WorkspaceSettings } from '@/api/types';

const DOCUMENTS: { key: DocumentKind; label: string; tab: string }[] = [
  { key: 'prescription', label: 'Receta', tab: 'Receta' },
  { key: 'certificate', label: 'Certificado médico', tab: 'Certificado' },
  { key: 'order', label: 'Orden de exámenes', tab: 'Orden' },
];

type SheetUse = 'off' | 'background' | 'margins';
type RxLayout = 'landscape' | 'portrait';

const SHEET_USES: { key: SheetUse; label: string; hint: string }[] = [
  { key: 'off', label: 'No tengo hoja', hint: 'Los documentos salen con el membrete de Elosar: tu logo y tus datos.' },
  { key: 'background', label: 'Sale de fondo', hint: 'Tu hoja va detrás del texto en el PDF: para enviarlo por WhatsApp o correo, o imprimirlo en papel blanco.' },
  { key: 'margins', label: 'Imprimo en mi papel', hint: 'Imprimes sobre tu papel ya impreso: no sale fondo y el texto cae dentro de sus márgenes.' },
];

const SHEET_PAPER: Record<SheetUse, Paper> = { off: 'letterhead', background: 'sheet', margins: 'preprinted' };

const RX_LAYOUTS: { key: RxLayout; label: string; hint: string }[] = [
  { key: 'landscape', label: 'Horizontal · dos mitades', hint: 'Receta e indicaciones lado a lado en una A4 acostada, para cortar por la mitad.' },
  { key: 'portrait', label: 'Vertical · una hoja', hint: 'Una A4 parada, sin cortar: la receta arriba y las indicaciones abajo.' },
];

/**
 * The paper of each document, from the two things the owner decides: how the
 * sheet is used (the certificate and the order always follow it) and whether
 * the receta takes the Elosar letterhead instead.
 */
function withPaper(form: PrescriptionSettings, rxLetterhead: boolean): PrescriptionSettings {
  const shared = SHEET_PAPER[(form.sheet_mode ?? 'off') as SheetUse];
  return {
    ...form,
    paper: {
      prescription: rxLetterhead && shared !== 'letterhead' ? 'letterhead' : shared,
      certificate: shared,
      order: shared,
    },
  };
}

function initialForm(settings: WorkspaceSettings): PrescriptionSettings {
  const read = readPrescriptionSettings(settings);
  const paper = read.paper as PaperByDocument;
  // The certificate stands for both one-page documents.
  const use: SheetUse = paper.certificate === 'sheet' ? 'background' : paper.certificate === 'preprinted' ? 'margins' : 'off';
  return withPaper({ ...read, sheet_mode: use }, paper.prescription === 'letterhead' && use !== 'off');
}

const SIDES: { key: keyof SheetMargins; label: string }[] = [
  { key: 'top', label: 'Arriba' },
  { key: 'right', label: 'Derecha' },
  { key: 'bottom', label: 'Abajo' },
  { key: 'left', label: 'Izquierda' },
];

/** How long the form has to sit still before the preview is redrawn. */
export const paperTiming = { previewDelayMs: 700 };

function useSettled<T>(value: T, delay: number): T {
  const [settled, setSettled] = useState(value);
  useEffect(() => {
    const id = window.setTimeout(() => setSettled(value), delay);
    return () => window.clearTimeout(id);
  }, [value, delay]);
  return settled;
}

/**
 * Ajustes → Papelería de documentos. Two decisions: how the clinic's sheet is
 * used (the certificate and the study order always follow it — they are one
 * page) and the receta's format (two halves or one portrait page, on the sheet
 * or with the Elosar letterhead). Beside it, the REAL PDF drawn with what is on
 * screen, saved or not.
 *
 * Replaces the two cards that used to fight each other: switching the designed
 * sheet on silently removed the letterhead from every document at once, and
 * the only way to see the result was a PDF in another tab.
 */
export function DocumentPaperSettings({ settings }: { settings: WorkspaceSettings }) {
  const qc = useQueryClient();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  // The paper belongs to the clinic and every doctor prints on it, so whoever
  // runs the front desk may set it up (settings.letterhead), not only the owner.
  const { can } = useWorkspaceCapabilities();
  const owner = settings.is_owner || can('settings.letterhead');

  const [form, setForm] = useState<PrescriptionSettings>(() => initialForm(settings));
  const [saved, setSaved] = useState(() => JSON.stringify(initialForm(settings)));
  const [previewDoc, setPreviewDoc] = useState<DocumentKind>('prescription');
  const [error, setError] = useState<string | null>(null);

  // A new workspace is a new form; an upload refreshing `settings` is not —
  // it must not wipe what the owner is still typing.
  useEffect(() => {
    const fresh = initialForm(settings);
    setForm(fresh);
    setSaved(JSON.stringify(fresh));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settings.id]);

  const margins = form.sheet_margins ?? DEFAULT_SHEET_MARGINS;
  const sheetUse = (form.sheet_mode ?? 'off') as SheetUse;
  const rxLayout = (form.prescription_layout ?? 'landscape') as RxLayout;
  const usesSheet = sheetUse !== 'off';
  const sheetPrinted = sheetUse === 'background';
  const rxLetterhead = usesSheet && (form.paper as PaperByDocument).prescription === 'letterhead';
  const usesLetterhead = !usesSheet || rxLetterhead || Boolean(form.sheet_keeps_header);
  const imageHeader = (form.header_mode ?? 'digital') === 'image';
  const dirty = JSON.stringify(form) !== saved;

  const set = (patch: Partial<PrescriptionSettings>) => setForm((f) => ({ ...f, ...patch }));
  const setSheetUse = (use: SheetUse) => {
    setForm((f) => withPaper({ ...f, sheet_mode: use }, rxLetterhead));
    if (previewDoc === 'prescription' && rxLetterhead) setPreviewDoc('certificate');
  };
  const setRxLetterhead = (value: boolean) => {
    setForm((f) => withPaper(f, value));
    setPreviewDoc('prescription');
  };
  const setRxLayout = (layout: RxLayout) => {
    set({ prescription_layout: layout });
    setPreviewDoc('prescription');
  };
  const setMargin = (side: keyof SheetMargins, value: string) => {
    const parsed = value === '' ? 0 : Number(value);
    if (Number.isNaN(parsed)) return;
    set({ sheet_margins: { ...margins, [side]: Math.max(0, Math.min(100, parsed)) } });
  };

  // ── Preview: the real document, redrawn once the form sits still ──────────
  const draft = useSettled(form, paperTiming.previewDelayMs);
  const preview = useQuery({
    queryKey: [
      'paper-preview', activeWorkspaceId, previewDoc, JSON.stringify(draft),
      settings.logo_path, settings.header_image_path, settings.sheet_image_path,
    ],
    queryFn: () => previewDocumentPdf(previewDoc, draft),
    placeholderData: (previous) => previous,
    staleTime: Infinity,
    retry: false,
  });
  const [pdfUrl, setPdfUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!preview.data) return;
    const url = URL.createObjectURL(preview.data);
    setPdfUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [preview.data]);
  const updating = preview.isFetching || JSON.stringify(draft) !== JSON.stringify(form);
  // A failed redraw must not leave the previous PDF passing for the current
  // one: that is how a removed banner seemed to keep printing.
  const previewFailed = preview.isError && !preview.isFetching;
  const previewError = isAxiosError(preview.error) && !preview.error.response
    ? 'No se pudo conectar con el servidor. Revisa tu conexión y vuelve a intentar.'
    : 'No se pudo dibujar la vista previa con estos cambios. Si acabas de subir una imagen muy pesada, prueba con una más liviana.';

  // ── Writes ─────────────────────────────────────────────────────────────────
  const refresh = () => qc.invalidateQueries({ queryKey: ['settings'] });
  const save = useMutation({
    mutationFn: () => updateLetterhead(form),
    onSuccess: () => {
      setSaved(JSON.stringify(form));
      setError(null);
      void refresh();
      toast('Papelería guardada');
    },
    onError: (err) =>
      setError(
        isAxiosError(err) && err.response?.status === 403
          ? 'No tienes permiso para cambiar la papelería.'
          : 'No se pudo guardar. Revisa tu conexión y vuelve a intentar.',
      ),
  });
  const uploadFailed = () => setError('No se pudo subir la imagen (JPG o PNG, hasta 8 MB).');
  const logoUp = useMutation({ mutationFn: uploadLogo, onSuccess: refresh, onError: uploadFailed });
  const removeFailed = () => setError('No se pudo quitar la imagen. Vuelve a intentar.');
  const logoDel = useMutation({ mutationFn: deleteLogo, onSuccess: refresh, onError: removeFailed });
  const bannerUp = useMutation({ mutationFn: uploadHeaderImage, onSuccess: refresh, onError: uploadFailed });
  const bannerDel = useMutation({ mutationFn: deleteHeaderImage, onSuccess: refresh, onError: removeFailed });
  const sheetUp = useMutation({ mutationFn: uploadSheetImage, onSuccess: refresh, onError: uploadFailed });
  const sheetDel = useMutation({ mutationFn: deleteSheetImage, onSuccess: refresh, onError: removeFailed });

  const logoRef = useRef<HTMLInputElement>(null);
  const bannerRef = useRef<HTMLInputElement>(null);
  const sheetRef = useRef<HTMLInputElement>(null);
  const pick = (mutate: (file: File) => void) => (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) mutate(file);
    e.target.value = '';
  };

  const previewLabel = DOCUMENTS.find((d) => d.key === previewDoc)!;

  return (
    <div className="paper-layout">
      <div className="paper-form stack">
        {!owner && (
          <div className="alert amber">
            <span className="ai"><Icon name="shield" size={16} /></span>
            <div>
              <b>Solo lectura</b>
              <p>La papelería la configura quien administra {settings.is_clinic ? 'la clínica' : 'el consultorio'}. Todos tus documentos salen con ella; solo cambia el nombre y la firma de cada médico. Puedes ver cómo salen tus documentos.</p>
            </div>
          </div>
        )}

        {/* 1 · The clinic's sheet — once, for every document */}
        <Card padded={false}>
          <div className="card-head">
            <div className="pano-head-text">
              <h3>Tu hoja diseñada</h3>
              <span className="sub">El certificado y la orden de exámenes salen siempre en tu hoja. La receta, si quieres, también.</span>
            </div>
            {usesSheet && (settings.sheet_image_url ? <span className="badge">Hoja cargada</span> : <span className="badge amber">Sin hoja</span>)}
          </div>
          <div className="card-pad stack">
            <div className="paper-choices" role="radiogroup" aria-label="Cómo usas tu hoja">
              {SHEET_USES.map((u) => (
                <button key={u.key} type="button" role="radio" aria-checked={sheetUse === u.key} disabled={!owner}
                  className={`paper-choice ${sheetUse === u.key ? 'active' : ''}`} onClick={() => setSheetUse(u.key)}>
                  {u.label}
                </button>
              ))}
            </div>
            <p className="paper-note">{SHEET_USES.find((u) => u.key === sheetUse)?.hint}</p>

            {usesSheet && (
              <>
                {sheetPrinted && !settings.sheet_image_url && (
                  <p className="alert amber paper-alert" role="status">
                    Sube tu hoja: mientras no haya imagen, los documentos salen con el membrete de Elosar.
                  </p>
                )}
                <div className="row wrap paper-actions">
                  <input ref={sheetRef} type="file" accept="image/png,image/jpeg" hidden disabled={!owner} onChange={pick(sheetUp.mutate)} />
                  <button className="btn sm" type="button" disabled={!owner || sheetUp.isPending} onClick={() => sheetRef.current?.click()}>
                    <Icon name="upload" size={14} />
                    {sheetUp.isPending ? 'Subiendo…' : settings.sheet_image_url ? 'Cambiar hoja' : 'Subir hoja'}
                  </button>
                  {settings.sheet_image_url && (
                    <button className="btn sm ghost" type="button" disabled={!owner || sheetDel.isPending} onClick={() => sheetDel.mutate()}>
                      Quitar hoja
                    </button>
                  )}
                  <span className="paper-note">
                    {sheetPrinted
                      ? 'JPG o PNG de tu hoja A4 vertical completa, hasta 8 MB. Si tu diseño está en PDF, expórtalo como imagen.'
                      : 'Opcional: sube una foto o el diseño de tu papel para ver en la vista previa dónde cae el texto.'}
                  </span>
                </div>

                <div className="paper-margins">
                  {SIDES.map((s) => (
                    <label key={s.key} className="paper-margin">
                      <span className="field-label">{`Margen ${s.label.toLowerCase()} (mm)`}</span>
                      <input className="field mono" inputMode="decimal" disabled={!owner} value={margins[s.key]}
                        onChange={(e) => setMargin(s.key, e.target.value)} />
                    </label>
                  ))}
                </div>
                <p className="paper-note">
                  Mide en tu hoja cuánto ocupan el encabezado y el pie: el texto no los pisa. En la receta en dos
                  mitades, se aplican a cada mitad.
                </p>

                <label className="paper-check">
                  <input type="checkbox" disabled={!owner} checked={Boolean(form.sheet_keeps_header)}
                    onChange={(e) => set({ sheet_keeps_header: e.target.checked })} />
                  <span>
                    <b>Mi hoja es solo un marco: agrega mi logo y mis datos encima.</b>
                    <span className="paper-note">Déjalo apagado si tu hoja ya trae el nombre, el logo y el contacto de la clínica.</span>
                  </span>
                </label>
              </>
            )}
          </div>
        </Card>

        {/* 2 · The receta: its format, and its paper when there is a sheet */}
        <Card padded={false}>
          <div className="card-head">
            <div className="pano-head-text">
              <h3>Receta</h3>
              <span className="sub">Tu firma y tus registros se configuran en Perfil Profesional.</span>
            </div>
          </div>
          <div className="card-pad stack">
            <div className="stack-sm">
              <span className="field-label" id="rx-layout">Formato</span>
              <div className="paper-choices two" role="radiogroup" aria-labelledby="rx-layout">
                {RX_LAYOUTS.map((l) => (
                  <button key={l.key} type="button" role="radio" aria-checked={rxLayout === l.key} disabled={!owner}
                    className={`paper-choice ${rxLayout === l.key ? 'active' : ''}`} onClick={() => setRxLayout(l.key)}>
                    {l.label}
                  </button>
                ))}
              </div>
              <p className="paper-note">{RX_LAYOUTS.find((l) => l.key === rxLayout)?.hint}</p>
            </div>

            {usesSheet && (
              <div className="stack-sm">
                <span className="field-label" id="rx-paper">Papel de la receta</span>
                <div className="paper-choices two" role="radiogroup" aria-labelledby="rx-paper">
                  <button type="button" role="radio" aria-checked={!rxLetterhead} disabled={!owner}
                    className={`paper-choice ${!rxLetterhead ? 'active' : ''}`} onClick={() => setRxLetterhead(false)}>
                    {sheetPrinted ? 'En mi hoja' : 'En mi papel'}
                  </button>
                  <button type="button" role="radio" aria-checked={rxLetterhead} disabled={!owner}
                    className={`paper-choice ${rxLetterhead ? 'active' : ''}`} onClick={() => setRxLetterhead(true)}>
                    Con el membrete de Elosar
                  </button>
                </div>
              </div>
            )}

            <div className="paper-validity">
              <label className="field-label" htmlFor="paper-validity">Vigencia (días)</label>
              <input id="paper-validity" className="field mono" inputMode="numeric" disabled={!owner} placeholder="3"
                value={form.validity_days ?? ''}
                onChange={(e) => set({ validity_days: e.target.value ? Number(e.target.value) : null })} />
              <p className="paper-note">Imprime «Válida hasta» en la receta (MSP).</p>
            </div>
          </div>
        </Card>

        {/* 3 · The system letterhead, only when something prints it */}
        {usesLetterhead && (
          <Card padded={false}>
            <div className="card-head">
              <div className="pano-head-text">
                <h3>Membrete de Elosar</h3>
                <span className="sub">{usesSheet ? 'Lo que va arriba de la receta, o encima de tu hoja si es solo un marco.' : 'Lo que va arriba de tus documentos.'}</span>
              </div>
              {settings.logo_url && <img className="letterhead-thumb" src={settings.logo_url} alt="Logo" />}
            </div>
            <div className="card-pad form-grid">
              <div className="full">
                <span className="field-label" id="header-style">Estilo</span>
                <div className="pill-row" role="radiogroup" aria-labelledby="header-style">
                  <button
                    type="button" role="radio" aria-checked={!imageHeader} disabled={!owner}
                    className={`pill ${!imageHeader ? 'active' : ''}`} onClick={() => set({ header_mode: 'digital' })}
                  >
                    Con mis datos
                  </button>
                  <button
                    type="button" role="radio" aria-checked={imageHeader} disabled={!owner}
                    className={`pill ${imageHeader ? 'active' : ''}`} onClick={() => set({ header_mode: 'image' })}
                  >
                    Banner como imagen
                  </button>
                </div>
              </div>

              {imageHeader && (
                <div className="full stack-sm">
                  <span className="field-label">Banner (una franja con logo y contacto)</span>
                  {settings.header_image_url && <img className="paper-banner" src={settings.header_image_url} alt="Banner del membrete" />}
                  {settings.header_image_url && (
                    <div className="paper-height">
                      <label className="field-label" htmlFor="paper-banner-height">
                        Alto del membrete: {form.header_image_height ? ` mm` : 'automático'}
                      </label>
                      <input
                        id="paper-banner-height" type="range" min={15} max={70} step={5} disabled={!owner}
                        value={form.header_image_height ?? 25}
                        onChange={(e) => set({ header_image_height: Number(e.target.value) })}
                      />
                      <div className="row wrap paper-actions">
                        <span className="paper-note">Súbelo si tu membrete es grande y se ve pequeño.</span>
                        {form.header_image_height != null && (
                          <button className="btn sm ghost" type="button" disabled={!owner} onClick={() => set({ header_image_height: null })}>
                            Automático
                          </button>
                        )}
                      </div>
                    </div>
                  )}
                  <div className="row wrap paper-actions">
                    <input ref={bannerRef} type="file" accept="image/png,image/jpeg,image/webp" hidden disabled={!owner} onChange={pick(bannerUp.mutate)} />
                    <button className="btn sm" type="button" disabled={!owner || bannerUp.isPending} onClick={() => bannerRef.current?.click()}>
                      <Icon name="upload" size={14} />
                      {bannerUp.isPending ? 'Subiendo…' : settings.header_image_url ? 'Cambiar banner' : 'Subir banner'}
                    </button>
                    {settings.header_image_url && (
                      <button className="btn sm ghost" type="button" disabled={!owner || bannerDel.isPending} onClick={() => bannerDel.mutate()}>
                        Quitar banner
                      </button>
                    )}
                  </div>
                  {!settings.header_image_url && <p className="paper-note">Sin banner todavía: mientras tanto sale el membrete con tus datos.</p>}
                </div>
              )}

              {!imageHeader && (
                <>
                  <div className="full">
                    <span className="field-label">Logo</span>
                    <div className="row wrap paper-actions">
                      <input ref={logoRef} type="file" accept="image/png,image/jpeg,image/webp" hidden disabled={!owner} onChange={pick(logoUp.mutate)} />
                      <button className="btn sm" type="button" disabled={!owner || logoUp.isPending} onClick={() => logoRef.current?.click()}>
                        <Icon name="upload" size={14} />
                        {logoUp.isPending ? 'Subiendo…' : settings.logo_url ? 'Cambiar logo' : 'Subir logo'}
                      </button>
                      {settings.logo_url && (
                        <button className="btn sm ghost" type="button" disabled={!owner || logoDel.isPending} onClick={() => logoDel.mutate()}>
                          Quitar logo
                        </button>
                      )}
                    </div>
                  </div>
                  <div className="full">
                    <label className="field-label" htmlFor="paper-header">Encabezado</label>
                    <input id="paper-header" className="field" disabled={!owner} placeholder="Ej.: Clínica Metropolitana / Dr. Juan Pérez"
                      value={form.header ?? ''} onChange={(e) => set({ header: e.target.value })} />
                  </div>
                  <div className="full">
                    <label className="field-label" htmlFor="paper-subheader">Subtítulo</label>
                    <input id="paper-subheader" className="field" disabled={!owner} placeholder="Ej.: Medicina General · RUC 179…"
                      value={form.subheader ?? ''} onChange={(e) => set({ subheader: e.target.value })} />
                  </div>
                  <div className="full">
                    <label className="field-label" htmlFor="paper-address">Dirección</label>
                    <input id="paper-address" className="field" disabled={!owner}
                      value={form.address ?? ''} onChange={(e) => set({ address: e.target.value })} />
                  </div>
                  <div>
                    <label className="field-label" htmlFor="paper-phone">Teléfono</label>
                    <input id="paper-phone" className="field" disabled={!owner}
                      value={form.phone ?? ''} onChange={(e) => set({ phone: e.target.value })} />
                  </div>
                  <div>
                    <label className="field-label" htmlFor="paper-email">Correo</label>
                    <input id="paper-email" className="field" disabled={!owner}
                      value={form.email ?? ''} onChange={(e) => set({ email: e.target.value })} />
                  </div>
                </>
              )}

              <div className="full">
                <label className="field-label" htmlFor="paper-footer">Pie de página</label>
                <textarea id="paper-footer" className="field" rows={2} disabled={!owner} placeholder="Horario, redes, aviso legal…"
                  value={form.footer ?? ''} onChange={(e) => set({ footer: e.target.value })} />
              </div>
            </div>
          </Card>
        )}

        {owner && (
          <div className={`paper-savebar ${dirty ? 'dirty' : ''}`}>
            <span className="paper-savestate" role="status">
              {error ?? (dirty ? 'Tienes cambios sin guardar' : 'Todo guardado')}
            </span>
            <button className="btn primary" type="button" disabled={!dirty || save.isPending} onClick={() => save.mutate()}>
              {save.isPending ? 'Guardando…' : 'Guardar cambios'}
            </button>
          </div>
        )}
      </div>

      {/* The real PDF, beside the form */}
      <aside className="paper-preview" aria-label="Vista previa del documento">
        <div className="paper-preview-head">
          <div className="pill-row" role="tablist" aria-label="Documento a previsualizar">
            {DOCUMENTS.map((doc) => (
              <button
                key={doc.key}
                type="button"
                role="tab"
                aria-selected={previewDoc === doc.key}
                className={`pill ${previewDoc === doc.key ? 'active' : ''}`}
                onClick={() => setPreviewDoc(doc.key)}
              >
                {doc.tab}
              </button>
            ))}
          </div>
          <div className="row paper-preview-tools">
            <span className="paper-preview-state">{updating ? 'Actualizando…' : previewFailed ? 'No se actualizó' : dirty ? 'Sin guardar' : 'Así sale'}</span>
            {pdfUrl && (
              <a className="btn sm ghost" href={pdfUrl} target="_blank" rel="noreferrer">
                <Icon name="eye" size={14} /> Ver en grande
              </a>
            )}
          </div>
        </div>
        <div className={`paper-frame-wrap ${previewDoc === 'prescription' && rxLayout === 'landscape' ? 'landscape' : ''} ${updating ? 'is-updating' : ''}`}>
          {previewFailed ? (
            <div className="paper-frame-empty paper-frame-error" role="alert">
              <p>{previewError}</p>
              <button className="btn sm" type="button" onClick={() => void preview.refetch()}>Reintentar</button>
            </div>
          ) : pdfUrl ? (
            <iframe className="paper-frame" src={`${pdfUrl}#toolbar=0&navpanes=0&view=Fit`} title={`Vista previa: ${previewLabel.label}`} />
          ) : (
            <p className="paper-frame-empty">Preparando la vista previa…</p>
          )}
        </div>
        <p className="paper-note">
          {previewDoc === 'prescription'
            ? RX_LAYOUTS.find((l) => l.key === rxLayout)?.hint
            : 'Paciente de ejemplo, con la marca MUESTRA. Tu firma sale tal como está en tu perfil.'}
        </p>
      </aside>
    </div>
  );
}
