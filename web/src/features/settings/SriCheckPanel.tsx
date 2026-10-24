import { useMutation } from '@tanstack/react-query';
import { checkSriSetup } from '@/api/settings';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { explainSriError } from '@/features/invoices/sriErrors';
import type { SriDiagnosticStep, WorkspaceSettings } from '@/api/types';

/**
 * Runs the SRI setup diagnosis and shows the result step by step.
 *
 * Exists because the two failures that cost the most time — a RUC missing its
 * "001" and an establishment the SRI has closed — are invisible to any form.
 * The second one lives in the SRI's own records, so the only way to catch it
 * before a real invoice bounces is to ask the SRI with a throwaway voucher.
 */

const STEP_LABELS: Record<SriDiagnosticStep['key'], string> = {
  fiscal: 'Datos fiscales completos',
  certificate: 'Certificado electrónico',
  schema: 'El comprobante cumple el esquema del SRI',
  signature: 'Firma electrónica',
  connectivity: 'Conexión con el SRI',
  probe: 'Prueba real contra el SRI',
};

/** Why a step was not run. Only `probe` has a reason worth explaining. */
const SKIPPED_REASON: Partial<Record<SriDiagnosticStep['key'], string>> = {
  probe: 'No se prueba en producción: emitir ahí sería una factura con validez fiscal.',
};

/**
 * What to do about OUR failures, phrased for a diagnosis the user just ran.
 *
 * The shared catalog is written for an invoice, where a queued job retries on
 * its own — "se está reintentando solo" is true there and false here. Nothing
 * retries a diagnosis; the user has to press the button again.
 */
const INTERNAL_ACTION: Record<string, string> = {
  transport: 'El SRI no está respondiendo ahora mismo. Vuelve a intentarlo en unos minutos.',
  certificate: 'Vuelve a cargar el certificado .p12 arriba y comprueba su contraseña.',
  config: 'Completa los datos fiscales de arriba y vuelve a probar.',
  schema: 'Es un problema del sistema, no tuyo. Avísale a soporte.',
};

function toneFor(status: SriDiagnosticStep['status']): string {
  return status === 'ok' ? 'ok' : status === 'fail' ? 'fail' : 'wait';
}

export function SriCheckPanel({ settings }: { settings: WorkspaceSettings }) {
  const check = useMutation({ mutationFn: checkSriSetup });

  const failed = check.data?.steps.find((s) => s.status === 'fail');
  const explanation = failed?.error ? explainSriError(failed.error) : null;

  return (
    <Card style={{ marginTop: 20 }}>
      <div className="card-head flush">
        <div>
          <h3>Probar configuración</h3>
          <span className="sub">Antes de facturar de verdad</span>
        </div>
      </div>

      <p className="muted" style={{ marginBottom: 14 }}>
        Comprueba tus datos fiscales y tu certificado, y —en ambiente de pruebas— emite un
        comprobante desechable para saber qué opina el SRI. Así los errores aparecen aquí y no
        en la primera factura de un paciente.
      </p>

      <button
        className="btn primary"
        disabled={!settings.sri_certificate_uploaded || check.isPending}
        onClick={() => check.mutate()}
      >
        {check.isPending ? 'Probando…' : 'Probar configuración'}
      </button>

      {!settings.sri_certificate_uploaded && (
        <p className="muted" style={{ marginTop: 10, fontSize: 12.5 }}>
          Carga primero el certificado .p12 para poder probar.
        </p>
      )}

      {check.isPending && (
        <p className="muted" style={{ marginTop: 12, fontSize: 12.5 }}>
          Esto habla con el SRI y puede tardar unos segundos.
        </p>
      )}

      {check.isError && (
        <div className="alert red" style={{ marginTop: 14 }}>
          <span className="ai"><Icon name="alert" size={16} /></span>
          <div>
            <b>No se pudo completar la prueba</b>
            <p>Revisa tu conexión y vuelve a intentarlo.</p>
          </div>
        </div>
      )}

      {check.data && (
        <div className="timeline" style={{ marginTop: 18 }}>
          {check.data.steps.map((step) => (
            <div key={step.key} className={`tl-item ${toneFor(step.status)}`}>
              <div className="what">{STEP_LABELS[step.key]}</div>
              {step.detail && <div className="when">{step.detail}</div>}
              {step.status === 'skipped' && SKIPPED_REASON[step.key] && (
                <div className="when">{SKIPPED_REASON[step.key]}</div>
              )}
            </div>
          ))}
        </div>
      )}

      {explanation && (
        <div className="alert red" style={{ marginTop: 14 }}>
          <span className="ai"><Icon name="alert" size={16} /></span>
          <div>
            <b>{explanation.title}</b>
            <p>{explanation.detail}</p>
            <p>
              {failed?.error?.source === 'internal'
                ? INTERNAL_ACTION[failed.error.category] ?? explanation.action
                : explanation.action}
            </p>
          </div>
        </div>
      )}

      {check.data && !failed && (
        <div className="alert blue" style={{ marginTop: 14 }}>
          <span className="ai"><Icon name="check" size={16} /></span>
          <div>
            <b>Todo en orden</b>
            <p>
              {check.data.probed
                ? 'El SRI autorizó el comprobante de prueba. Ya puedes facturar.'
                : 'Las comprobaciones locales pasaron. En producción no se emite comprobante de prueba.'}
            </p>
          </div>
        </div>
      )}
    </Card>
  );
}
