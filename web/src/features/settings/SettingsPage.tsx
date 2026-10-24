import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { fetchSettings, updateSettings, uploadCertificate, type SettingsUpdate } from '@/api/settings';
import { DocumentPaperSettings } from './DocumentPaperSettings';
import { SriCheckPanel } from './SriCheckPanel';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { ThemePicker } from './ThemePicker';
import { EloAgentsPane } from './elo/EloAgentsPane';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { t } from '@/i18n/es';
import type { WorkspaceSettings } from '@/api/types';

const PANES = ['general', 'letterhead', 'fiscal', 'agents'] as const;
type Pane = (typeof PANES)[number];

/**
 * Fields this form actually renders. A 422 naming one of them can be shown
 * under the input; anything else has nowhere to land and falls back to the
 * general message.
 */
const FORM_FIELDS: readonly string[] = [
  'name',
  'ruc',
  'razon_social',
  'nombre_comercial',
  'dir_matriz',
  'dir_establecimiento',
  'cod_establecimiento',
  'pto_emision',
  'obligado_contabilidad',
  'sri_environment',
  'sri_enabled',
];

/** `?pane=` sent by the contextual notices elsewhere in the app. Anything the
 *  page does not know about lands on General rather than on a blank panel. */
function requestedPane(): Pane {
  const requested = new URLSearchParams(window.location.search).get('pane');

  return PANES.find((p) => p === requested) ?? 'general';
}

export function SettingsPage() {
  const qc = useQueryClient();
  const { can } = useWorkspaceCapabilities();
  // Every member sees Ajustes; only whoever manages the clinic edits its name
  // and fiscal data (the API enforces the same rule).
  const canManage = can('settings.manage');
  const [pane, setPane] = useState<Pane>(requestedPane);
  const [message, setMessage] = useState<string | null>(null);
  const shown: Pane = pane === 'fiscal' && !canManage ? 'general' : pane;

  const { data, isLoading } = useQuery({ queryKey: ['settings'], queryFn: fetchSettings });

  const {
    register,
    handleSubmit,
    reset,
    setError,
    clearErrors,
    formState: { errors },
  } = useForm<SettingsUpdate>();

  useEffect(() => {
    if (data) {
      reset({
        name: data.name,
        ruc: data.ruc ?? '',
        razon_social: data.razon_social ?? '',
        nombre_comercial: data.nombre_comercial ?? '',
        dir_matriz: data.dir_matriz ?? '',
        dir_establecimiento: data.dir_establecimiento ?? '',
        cod_establecimiento: data.cod_establecimiento ?? '',
        pto_emision: data.pto_emision ?? '',
        obligado_contabilidad: data.obligado_contabilidad,
        sri_environment: data.sri_environment ?? 'test',
        sri_enabled: data.sri_enabled,
      });
    }
  }, [data, reset]);

  const mutation = useMutation({
    mutationFn: (values: SettingsUpdate) => updateSettings(values),
    onSuccess: () => {
      clearErrors();
      qc.invalidateQueries({ queryKey: ['settings'] });
      setMessage('Configuración guardada ✓');
      setTimeout(() => setMessage(null), 2500);
    },
    /**
     * The server already says WHICH field is wrong and why (422 + `errors`).
     * Collapsing that into a generic "no se pudo guardar" is what sent someone
     * hunting for a 10-digit RUC for several minutes, so each message goes back
     * under the input it belongs to.
     */
    onError: (err) => {
      clearErrors();

      if (isAxiosError(err) && err.response?.status === 403) {
        setMessage('Solo el propietario puede editar la configuración fiscal.');
        return;
      }

      const body = isAxiosError(err)
        ? (err.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined)
        : undefined;

      const placed = Object.entries(body?.errors ?? {}).filter(([field]) => FORM_FIELDS.includes(field));

      placed.forEach(([field, messages]) => {
        setError(field as keyof SettingsUpdate, { type: 'server', message: messages[0] });
      });

      // Only fall back to a general line when nothing landed under a field —
      // otherwise the footer contradicts a message that already says what to fix.
      setMessage(placed.length > 0 ? 'Revisa los campos marcados.' : body?.message ?? 'No se pudo guardar.');
    },
  });

  /** The server's complaint about a field, rendered right under it. */
  const fieldError = (name: keyof SettingsUpdate) =>
    errors[name] ? <p className="field-error">{errors[name]?.message}</p> : null;

  if (isLoading || !data) return <Spinner label={t.common.loading} />;

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Ajustes</h1>
          <p>Preferencias, membrete clínico y facturación electrónica (SRI)</p>
        </div>
      </div>

      <div className="settings">
        <nav className="set-nav">
          <button className={shown === 'general' ? 'active' : ''} onClick={() => setPane('general')}>
            <Icon name="cog" /> General
          </button>
          <button className={shown === 'letterhead' ? 'active' : ''} onClick={() => setPane('letterhead')}>
            <Icon name="file" /> Papelería de documentos
          </button>
          {canManage && (
            <button className={shown === 'fiscal' ? 'active' : ''} onClick={() => setPane('fiscal')}>
            <Icon name="invoice" /> Facturación SRI
          </button>
          )}
          {can('assistant.use') && (
            <button className={shown === 'agents' ? 'active' : ''} onClick={() => setPane('agents')}>
              <Icon name="chat" /> Agentes
            </button>
          )}
        </nav>

        <div>
          {shown === 'general' && (
            <>
            <Card>
              <div className="card-head flush">
                <div>
                  <h3>Apariencia</h3>
                  <span className="sub">Prueba distintos estilos visuales para el panel médico</span>
                </div>
              </div>
              <ThemePicker />
            </Card>
            <Card style={{ marginTop: 20 }}>
              <div className="set-row">
                <div className="lbl">
                  <b>Espacio de trabajo</b>
                  <span>{data.is_clinic ? 'Clínica' : 'Consultorio personal'}</span>
                </div>
                <div className="set-ctrl">
                  <input
                    className={`field ${errors.name ? 'err' : ''}`.trim()}
                    style={{ minWidth: 240 }}
                    aria-invalid={errors.name ? true : undefined}
                    disabled={!canManage}
                    {...register('name')}
                  />
                  {fieldError('name')}
                </div>
              </div>
              {data.is_clinic && data.invite_code && (
                <div className="set-row">
                  <div className="lbl">
                    <b>Código de invitación</b>
                    <span>Compártelo para que otros médicos se unan</span>
                  </div>
                  <div className="set-ctrl">
                    <span className="badge accent mono">{data.invite_code}</span>
                  </div>
                </div>
              )}
              {canManage && (
              <div className="row between" style={{ marginTop: 18 }}>
                <span className="muted" style={{ fontSize: 12.5 }}>
                  {message}
                </span>
                <button
                  className="btn primary"
                  onClick={handleSubmit((v) => mutation.mutate({ name: v.name }))}
                  disabled={mutation.isPending}
                >
                  Guardar
                </button>
              </div>
              )}
            </Card>
            </>
          )}

          {shown === 'letterhead' && (
            <DocumentPaperSettings settings={data} />
          )}

          {shown === 'fiscal' && (
            <Card>
              <form className="form-grid" onSubmit={handleSubmit((v) => mutation.mutate(v))}>
                <div className="full">
                  <div className={`badge ${data.sri_enabled ? 'green' : 'amber'}`}>
                    {data.sri_enabled ? 'SRI habilitado' : 'SRI deshabilitado'}
                  </div>
                  {/* Almost everything below is copied off one piece of paper.
                      Saying so up front saves hunting field by field. */}
                  <p className="field-hint" style={{ marginTop: 10 }}>
                    Los campos con <b>*</b> son los que el SRI necesita para autorizar tus facturas. Salen de
                    tu certificado de RUC, salvo el código de establecimiento, que consultas en el portal del SRI.
                  </p>
                </div>
                <div>
                  <label className="field-label" htmlFor="set-ruc">RUC *</label>
                  <input
                    id="set-ruc"
                    className={`field mono ${errors.ruc ? 'err' : ''}`.trim()}
                    maxLength={13}
                    aria-invalid={errors.ruc ? true : undefined}
                    {...register('ruc')}
                  />
                  <p className="field-hint">13 dígitos: tu cédula + 001.</p>
                  {fieldError('ruc')}
                </div>
                <div>
                  <label className="field-label" htmlFor="set-razon-social">Razón social *</label>
                  <input
                    id="set-razon-social"
                    className={`field ${errors.razon_social ? 'err' : ''}`.trim()}
                    aria-invalid={errors.razon_social ? true : undefined}
                    {...register('razon_social')}
                  />
                  <p className="field-hint">Tus nombres y apellidos como constan en el RUC.</p>
                  {fieldError('razon_social')}
                </div>
                <div className="full">
                  <label className="field-label" htmlFor="set-nombre-comercial">Nombre comercial</label>
                  <input
                    id="set-nombre-comercial"
                    className={`field ${errors.nombre_comercial ? 'err' : ''}`.trim()}
                    aria-invalid={errors.nombre_comercial ? true : undefined}
                    {...register('nombre_comercial')}
                  />
                  {fieldError('nombre_comercial')}
                </div>
                <div className="full">
                  <label className="field-label" htmlFor="set-dir-matriz">Dirección matriz *</label>
                  <input
                    id="set-dir-matriz"
                    className={`field ${errors.dir_matriz ? 'err' : ''}`.trim()}
                    aria-invalid={errors.dir_matriz ? true : undefined}
                    {...register('dir_matriz')}
                  />
                  <p className="field-hint">La que consta en tu certificado de RUC.</p>
                  {fieldError('dir_matriz')}
                </div>
                <div className="full">
                  <label className="field-label" htmlFor="set-dir-establecimiento">Dirección establecimiento</label>
                  <input
                    id="set-dir-establecimiento"
                    className={`field ${errors.dir_establecimiento ? 'err' : ''}`.trim()}
                    aria-invalid={errors.dir_establecimiento ? true : undefined}
                    {...register('dir_establecimiento')}
                  />
                  <p className="field-hint">Dónde atiendes, si es distinta de la matriz.</p>
                  {fieldError('dir_establecimiento')}
                </div>
                <div>
                  <label className="field-label" htmlFor="set-cod-establecimiento">Cód. establecimiento *</label>
                  <input
                    id="set-cod-establecimiento"
                    className={`field mono ${errors.cod_establecimiento ? 'err' : ''}`.trim()}
                    maxLength={3}
                    aria-invalid={errors.cod_establecimiento ? true : undefined}
                    {...register('cod_establecimiento')}
                  />
                  {/* This one is not on any paper the doctor owns — it lives in
                      the SRI's own records, and getting it wrong is error 56. */}
                  <p className="field-hint">3 dígitos. Es el que tienes abierto en el portal del SRI (001 si solo tienes uno).</p>
                  {fieldError('cod_establecimiento')}
                </div>
                <div>
                  <label className="field-label" htmlFor="set-pto-emision">Punto de emisión *</label>
                  <input
                    id="set-pto-emision"
                    className={`field mono ${errors.pto_emision ? 'err' : ''}`.trim()}
                    maxLength={3}
                    aria-invalid={errors.pto_emision ? true : undefined}
                    {...register('pto_emision')}
                  />
                  <p className="field-hint">3 dígitos. Normalmente 001.</p>
                  {fieldError('pto_emision')}
                </div>
                <div>
                  <label className="field-label">Ambiente SRI</label>
                  <select className="field" {...register('sri_environment')}>
                    <option value="test">Pruebas</option>
                    <option value="production">Producción</option>
                  </select>
                </div>
                <div className="set-row full">
                  <div className="lbl">
                    <b>Habilitar facturación electrónica</b>
                    <span>
                      {data.sri_certificate_uploaded
                        ? 'Cada factura nueva se enviará al SRI automáticamente'
                        : 'Sube primero el certificado .p12 (abajo) para poder habilitarlo'}
                    </span>
                  </div>
                  <label className="switch">
                    <input
                      type="checkbox"
                      disabled={!data.sri_certificate_uploaded}
                      {...register('sri_enabled')}
                    />
                    <span className="track" />
                  </label>
                </div>
                <div className="set-row full" style={{ borderBottom: 'none' }}>
                  <div className="lbl">
                    <b>Obligado a llevar contabilidad</b>
                  </div>
                  <label className="switch">
                    <input type="checkbox" {...register('obligado_contabilidad')} />
                    <span className="track" />
                  </label>
                </div>
                <div className="full row between">
                  <span className="muted" style={{ fontSize: 12.5 }}>
                    {message ?? (data.sri_certificate_uploaded ? 'Certificado .p12 cargado ✓' : 'Certificado pendiente — cárgalo más abajo')}
                  </span>
                  <button className="btn primary" type="submit" disabled={mutation.isPending}>
                    {mutation.isPending ? 'Guardando…' : 'Guardar datos fiscales'}
                  </button>
                </div>
              </form>
            </Card>
          )}

          {shown === 'fiscal' && <CertificateCard settings={data} />}
          {shown === 'fiscal' && <SriCheckPanel settings={data} />}
          {shown === 'agents' && can('assistant.use') && <EloAgentsPane />}
        </div>
      </div>
    </>
  );
}

/**
 * SRI electronic-signature certificate (.p12). This is NOT the doctor's
 * handwritten signature (that lives in Perfil) — it's the BCE-issued certificate
 * required to legally authorize electronic invoices with the SRI.
 */
function CertificateCard({ settings }: { settings: WorkspaceSettings }) {
  const qc = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [password, setPassword] = useState('');

  const upload = useMutation({
    mutationFn: () => uploadCertificate(file!, password),
    onSuccess: () => {
      setFile(null);
      setPassword('');
      qc.invalidateQueries({ queryKey: ['settings'] });
    },
  });

  return (
    <Card style={{ marginTop: 20 }}>
      <div className="card-head flush">
        <div>
          <h3>Firma electrónica SRI (.p12)</h3>
          <span className="sub">Certificado del BCE para autorizar facturas electrónicas</span>
        </div>
        <span className={`badge ${settings.sri_certificate_uploaded ? 'green' : 'amber'}`}>
          {settings.sri_certificate_uploaded ? 'Certificado cargado' : 'No configurado'}
        </span>
      </div>

      <div className="alert amber" style={{ marginBottom: 16 }}>
        <span className="ai"><Icon name="shield" size={16} /></span>
        <div>
          <b>Distinto a la firma del médico</b>
          <p>
            Esto es el certificado digital (.p12) emitido por una entidad acreditada del BCE, necesario para
            la <b>facturación electrónica</b>. La firma manuscrita para recetas se gestiona en <b>Perfil</b>.
            Mientras no cargues un certificado válido, las facturas se emiten como documento interno.
          </p>
        </div>
      </div>

      <div className="form-grid">
        <div>
          <label className="field-label">Archivo .p12 / .pfx</label>
          <input
            ref={fileRef}
            type="file"
            accept=".p12,.pfx"
            hidden
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          />
          <button className="btn" type="button" onClick={() => fileRef.current?.click()}>
            <Icon name="upload" size={15} /> {file ? file.name : 'Seleccionar archivo'}
          </button>
        </div>
        <div>
          <label className="field-label">Contraseña del certificado</label>
          <input className="field" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        <div className="full row between">
          <span className="muted" style={{ fontSize: 12.5 }}>
            {upload.isError
              ? (isAxiosError(upload.error) && (upload.error.response?.data as { message?: string })?.message) || 'No se pudo cargar.'
              : upload.isSuccess
                ? 'Certificado cargado ✓'
                : 'El archivo se guarda cifrado; solo el propietario puede cargarlo.'}
          </span>
          <button className="btn primary" disabled={!file || !password || upload.isPending} onClick={() => upload.mutate()}>
            {upload.isPending ? 'Cargando…' : 'Cargar certificado'}
          </button>
        </div>
      </div>
    </Card>
  );
}
