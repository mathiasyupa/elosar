import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/Button';
import { Icon } from '@/components/Icon';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { createPatientAlert, fetchPatientAlerts, resolvePatientAlert } from '@/api/obstetrics';
import type { PatientAlert, PatientAlertSeverity } from '@/api/types';

const SEVERITIES: Record<PatientAlertSeverity, string> = {
  info: 'Informativa',
  warning: 'Advertencia',
  critical: 'Crítica',
};

/** El tono del chip sale de la gravedad, del mismo juego semántico del resto. */
export function alertTone(severity: PatientAlertSeverity): string {
  return severity === 'critical' ? 'red' : severity === 'warning' ? 'amber' : 'blue';
}

/** El aviso tal como se ve pegado al nombre del paciente, en el riel y en la
 *  cabecera de atención. Solo llegan los vigentes. */
export function PatientAlertChip({ alert }: { alert: PatientAlert }) {
  return (
    <span className={`alert-chip ${alertTone(alert.severity)}`} title={alert.severity_label}>
      <Icon name="alert" size={12} />
      <span className="sr-only">Aviso {SEVERITIES[alert.severity]}: </span>
      {alert.text}
    </span>
  );
}

/**
 * Gestión de los avisos permanentes del paciente.
 *
 * Un aviso se **resuelve**, no se borra: «dejó de aplicar tal día» es información
 * clínica, y un aviso que se puede hacer desaparecer no lo audita nadie. Por eso
 * hay «Ya no aplica» y no una papelera.
 */
export function PatientAlertsPanel({ patientId }: { patientId: number }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const { can } = useWorkspaceCapabilities();
  const canEdit = can('records.manage');
  const qc = useQueryClient();

  const [text, setText] = useState('');
  const [severity, setSeverity] = useState<PatientAlertSeverity>('warning');

  const alerts = useQuery({
    queryKey: ['patient-alerts', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientAlerts(patientId),
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['patient-alerts', activeWorkspaceId, patientId] });
    // El riel y la cabecera los leen desde el paciente.
    qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
  };

  const add = useMutation({
    mutationFn: () => createPatientAlert(patientId, { text: text.trim(), severity }),
    onSuccess: () => {
      setText('');
      invalidate();
      toast('Aviso añadido.', 'success');
    },
    onError: () => toast('No se pudo añadir el aviso.', 'error'),
  });

  const resolve = useMutation({
    mutationFn: (id: number) => resolvePatientAlert(id),
    onSuccess: () => {
      invalidate();
      toast('Aviso cerrado.', 'success');
    },
    onError: () => toast('No se pudo cerrar el aviso.', 'error'),
  });

  const active = (alerts.data ?? []).filter((a) => a.is_active);

  return (
    <div className="alerts-panel">
      {active.length === 0 ? (
        <p className="muted">Sin avisos vigentes.</p>
      ) : (
        <ul className="alerts-list">
          {active.map((a) => (
            <li key={a.id}>
              <PatientAlertChip alert={a} />
              {canEdit && (
                <button
                  className="btn ghost sm"
                  disabled={resolve.isPending}
                  onClick={() => resolve.mutate(a.id)}
                >
                  Ya no aplica
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {canEdit && (
        <div className="row wrap alerts-add">
          <input
            className="field"
            value={text}
            maxLength={255}
            placeholder="Portadora de marcapasos, alergia al látex…"
            onChange={(e) => setText(e.target.value)}
            aria-label="Texto del aviso"
          />
          <select
            className="field"
            value={severity}
            onChange={(e) => setSeverity(e.target.value as PatientAlertSeverity)}
            aria-label="Gravedad del aviso"
          >
            {Object.entries(SEVERITIES).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </select>
          <Button size="sm" disabled={!text.trim() || add.isPending} onClick={() => add.mutate()}>
            Añadir
          </Button>
        </div>
      )}
      {/* Se muestra junto al nombre en cada pantalla, así que un párrafo aquí deja
          de leerse y el aviso deja de servir. */}
      {canEdit && <p className="field-hint">Una línea. Se muestra junto al nombre del paciente en todas las pantallas.</p>}
    </div>
  );
}
