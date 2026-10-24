import { patientTabName } from '@/lib/openPatient';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import type { QueueRow, QueueVitals } from '@/api/types';

/** The few readings a doctor glances at before calling the patient in. */
function vitalChips(v: QueueVitals): { label: string; alert: boolean }[] {
  const chips: { label: string; alert: boolean }[] = [];
  if (v.blood_pressure_sys != null && v.blood_pressure_dia != null) {
    chips.push({ label: `PA ${v.blood_pressure_sys}/${v.blood_pressure_dia}`, alert: v.blood_pressure_sys >= 140 || v.blood_pressure_dia >= 90 });
  }
  if (v.heart_rate != null) chips.push({ label: `FC ${v.heart_rate}`, alert: v.heart_rate > 100 || v.heart_rate < 50 });
  if (v.temperature != null) chips.push({ label: `${v.temperature} °C`, alert: Number(v.temperature) >= 38 });
  if (v.oxygen_saturation != null) chips.push({ label: `SpO2 ${v.oxygen_saturation}%`, alert: v.oxygen_saturation < 94 });
  if (v.respiratory_rate != null) chips.push({ label: `FR ${v.respiratory_rate}`, alert: v.respiratory_rate > 22 });
  if (v.weight_kg != null) chips.push({ label: `${v.weight_kg} kg`, alert: false });
  return chips;
}

/**
 * «Sala de espera» for the clinic's doctors: everyone who arrived today and is
 * not attended yet, whoever they were booked with — when a doctor does not come
 * in, a colleague takes the patient. The vitals show before the patient is
 * called in, and whoever presses Atender keeps the visit (the server hands the
 * appointment over on save).
 */
export function WaitingRoom({ rows }: { rows: QueueRow[] }) {
  const waiting = rows
    .filter((row) => row.status === 'vitals_done' || row.status === 'checked_in')
    .sort((a, b) => (a.checked_in_at ?? a.time ?? '').localeCompare(b.checked_in_at ?? b.time ?? ''));

  if (waiting.length === 0) return null;

  return (
    <Card padded={false}>
      <div className="card-head">
        <div>
          <h3>Sala de espera</h3>
          <span className="sub">Pacientes que ya llegaron, en orden de llegada</span>
        </div>
      </div>
      <div className="card-pad dashboard-rail-list">
        {waiting.map((row) => (
          <div key={row.id} className="recent-row">
            <span className="avatar-sm dashboard-avatar">
              <Icon name="user" size={15} />
            </span>
            <div className="dashboard-row-body">
              <b>{row.patient?.name ?? 'Paciente'}</b>
              <span className="muted">
                {row.status === 'vitals_done' ? 'Signos tomados' : 'Esperando signos'}
                {row.doctor ? ` · agendado con ${row.doctor.name}` : ''}
              </span>
              {row.vitals && (
                <div className="row wrap waiting-vitals">
                  {vitalChips(row.vitals).map((chip) => (
                    <span key={chip.label} className={`badge mono ${chip.alert ? 'red' : 'blue'}`}>
                      {chip.label}
                    </span>
                  ))}
                </div>
              )}
            </div>
            {row.patient && (
              <Link
                className="btn sm primary"
                to={`/pacientes/${row.patient.id}/atencion?appointment=${row.id}`}
                target={patientTabName(row.patient.id)}
              >
                Atender
              </Link>
            )}
          </div>
        ))}
      </div>
    </Card>
  );
}
