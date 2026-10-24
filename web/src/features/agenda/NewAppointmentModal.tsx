import { useOpenPatient } from '@/lib/openPatient';
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { addDays, format, parseISO } from 'date-fns';
import {
  cancelAppointment,
  checkInAppointment,
  completeAppointment,
  createAppointment,
  markReminded,
  sendConfirmationEmail,
  sendReminderEmail,
  updateAppointment,
} from '@/api/appointments';
import { PatientPicker } from '@/components/PatientPicker';
import { ServicePicker } from '@/components/ServicePicker';
import { Modal } from '@/components/ui/Modal';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { EmailButton } from '@/components/EmailButton';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useUiStore } from '@/store/ui';
import { formatDate, formatTime } from '@/i18n/es';
import { APPOINTMENT_TYPE_LABELS, BOOKABLE_APPOINTMENT_TYPES, labelFor } from '@/i18n/labels';
import { apptConfirmationMessage, apptReminderMessage, apptRescheduleMessage } from '@/lib/whatsapp';
import { getAppointmentCustomColor, setAppointmentCustomColor } from './calendar';
import type { Appointment, Patient, TeamMember } from '@/api/types';

const COLOR_SWATCHES = [
  { id: '', label: 'Auto', bg: 'var(--surface-2)', color: 'var(--text-2)' },
  { id: 'color-teal', label: 'Teal', bg: 'var(--accent-soft)', color: 'var(--accent)' },
  { id: 'color-green', label: 'Verde', bg: 'var(--green-soft)', color: 'var(--green)' },
  { id: 'color-violet', label: 'Violeta', bg: 'var(--violet-soft)', color: 'var(--violet)' },
  { id: 'color-amber', label: 'Ámbar', bg: 'var(--amber-soft)', color: 'var(--amber)' },
  { id: 'color-blue', label: 'Azul', bg: 'var(--blue-soft)', color: 'var(--blue)' },
  { id: 'color-red', label: 'Rojo', bg: 'var(--red-soft)', color: 'var(--red)' },
];

interface Props {
  /** Edit/view an existing appointment. */
  appointment?: Appointment;
  /** Create mode: prefilled slot. */
  defaultDate?: string;
  defaultTime?: string;
  defaultDoctorId?: number;
  /** Create mode: who it is for. Booking the next control straight from the
   *  encounter already knows the patient — asking again invites picking the
   *  wrong one. */
  defaultPatient?: Patient | null;
  defaultType?: string;
  clinicDoctors?: TeamMember[];
  onClose: () => void;
  onCreated: () => void;
}

function apiError(error: unknown, fallback: string): string {
  if (isAxiosError(error)) return (error.response?.data as { message?: string })?.message ?? fallback;
  return fallback;
}

export function AppointmentModal({
  appointment,
  defaultDate,
  defaultTime,
  defaultDoctorId,
  defaultPatient = null,
  defaultType,
  clinicDoctors,
  onClose,
  onCreated,
}: Props) {
  const editing = !!appointment;
  const openPatient = useOpenPatient();
  const { can, isClinic, hasRole, isOwner } = useWorkspaceCapabilities();

  // A clinical-only member only books for themselves — the server forces their
  // own id anyway. Hide the doctor selector and never send a doctor_id, so the
  // appointment is honestly attributed to them and never to a colleague.
  const strictDoctor =
    isClinic && hasRole('doctor') && !isOwner && !hasRole('admin') && !hasRole('receptionist');
  const apptStart = appointment?.appointment_date ? parseISO(appointment.appointment_date) : null;

  const [patient, setPatient] = useState<Patient | null>(appointment?.patient ?? defaultPatient);
  const [date, setDate] = useState(apptStart ? format(apptStart, 'yyyy-MM-dd') : defaultDate ?? '');
  const [time, setTime] = useState(apptStart ? format(apptStart, 'HH:mm') : defaultTime ?? '09:00');
  const [type, setType] = useState(appointment?.type ?? defaultType ?? 'consultation');
  const [duration, setDuration] = useState(String(appointment?.duration_minutes ?? 30));
  const [doctorId, setDoctorId] = useState(
    String(appointment?.doctor_id ?? defaultDoctorId ?? clinicDoctors?.[0]?.doctor_id ?? ''),
  );
  const [serviceId, setServiceId] = useState<number | null>(appointment?.medical_service_id ?? null);
  const [customColor, setCustomColor] = useState<string>(() =>
    appointment ? (getAppointmentCustomColor(appointment.id) ?? '') : '',
  );

  // Dedicated reschedule sub-view state & post-reschedule summary state
  const [isRescheduling, setIsRescheduling] = useState(false);
  const [rescheduledSummary, setRescheduledSummary] = useState<{ oldIso: string; newIso: string } | null>(null);

  // After a successful booking we show a confirmation step with a one-click
  // WhatsApp message before closing the modal.
  const [created, setCreated] = useState<Appointment | null>(null);

  const create = useMutation({
    mutationFn: () =>
      createAppointment({
        patient_id: patient!.id,
        appointment_date: `${date}T${time}:00`,
        type,
        medical_service_id: serviceId,
        duration_minutes: Number(duration),
        ...(!strictDoctor && doctorId ? { doctor_id: Number(doctorId) } : {}),
      }),
    onSuccess: (appt) => {
      if (customColor) {
        setAppointmentCustomColor(appt.id, customColor);
      }
      setCreated(appt);
    },
  });

  const doctorName = clinicDoctors?.find((d) => String(d.doctor_id) === doctorId)?.name ?? null;

  const reschedule = useMutation({
    mutationFn: () =>
      updateAppointment(appointment!.id, {
        appointment_date: `${date}T${time}:00`,
        type,
        medical_service_id: serviceId,
        duration_minutes: Number(duration),
        ...(!strictDoctor && doctorId ? { doctor_id: Number(doctorId) } : {}),
      }),
    onSuccess: () => {
      if (appointment) {
        setAppointmentCustomColor(appointment.id, customColor || null);
        setRescheduledSummary({
          oldIso: appointment.appointment_date ?? new Date().toISOString(),
          newIso: `${date}T${time}:00`,
        });
      } else {
        onCreated();
      }
    },
  });

  const toast = useUiStore((s) => s.toast);
  const sendEmail = useMutation({
    mutationFn: () => sendConfirmationEmail(created!.id),
    onSuccess: () => toast('Correo de confirmación enviado al paciente.', 'success'),
    onError: () => toast('No se pudo enviar el correo. ¿El paciente tiene email?', 'error'),
  });
  // Reminder actions while editing an existing scheduled appointment.
  const emailReminder = useMutation({
    mutationFn: () => sendReminderEmail(appointment!.id),
    onSuccess: () => toast('Recordatorio enviado por correo.', 'success'),
    onError: () => toast('No se pudo enviar el correo. ¿El paciente tiene email?', 'error'),
  });
  const markWhatsApp = useMutation({ mutationFn: () => markReminded(appointment!.id) });

  const checkIn = useMutation({ mutationFn: () => checkInAppointment(appointment!.id), onSuccess: onCreated });
  const complete = useMutation({ mutationFn: () => completeAppointment(appointment!.id), onSuccess: onCreated });
  const cancel = useMutation({ mutationFn: () => cancelAppointment(appointment!.id), onSuccess: onCreated });

  const busy =
    create.isPending || reschedule.isPending || checkIn.isPending || complete.isPending || cancel.isPending;
  const error =
    [create, reschedule, checkIn, complete, cancel].find((m) => m.isError)?.error ?? null;
  const isScheduled = appointment?.status === 'scheduled';
  const showDoctorSelect = !strictDoctor && clinicDoctors && clinicDoctors.length > 0;

  // Post-booking confirmation: offer to notify the patient via WhatsApp.
  if (created) {
    const confirmMessage = apptConfirmationMessage({
      name: patient?.name ?? 'paciente',
      dateLabel: formatDate(`${date}T${time}:00`),
      timeLabel: time,
      doctorName,
    });
    return (
      <Modal
        title="Cita agendada"
        onClose={onCreated}
        footer={<button className="btn primary" onClick={onCreated}>Listo</button>}
      >
        <div className="stack" style={{ gap: 12 }}>
          <div className="alert green">
            <span className="ai">✓</span>
            <div>
              <b>Cita agendada para {patient?.name ?? 'el paciente'}</b>
              <p>{formatDate(`${date}T${time}:00`)} a las {time}{doctorName ? ` · ${doctorName}` : ''}</p>
            </div>
          </div>
          <p className="muted" style={{ fontSize: 13, margin: 0 }}>
            Avisa al paciente ahora. WhatsApp abre el chat con el mensaje listo; el correo lo envía el
            sistema a nombre del médico.
          </p>
          <div className="row wrap" style={{ gap: 8 }}>
            <WhatsAppButton
              phone={patient?.phone}
              message={confirmMessage}
              label="Confirmar por WhatsApp"
            />
            <EmailButton
              email={patient?.email}
              pending={sendEmail.isPending}
              onSend={() => sendEmail.mutate()}
              label="Confirmar por correo"
            />
          </div>
        </div>
      </Modal>
    );
  }

  // Post-reschedule confirmation: clear feedback card with new date/time & patient notifications.
  if (rescheduledSummary && appointment) {
    const rescheduleMessage = apptRescheduleMessage({
      name: appointment.patient?.name ?? 'paciente',
      dateLabel: formatDate(rescheduledSummary.newIso),
      timeLabel: formatTime(rescheduledSummary.newIso),
      doctorName: appointment.doctor_name ?? doctorName,
    });
    return (
      <Modal
        title="Cita reprogramada"
        onClose={onCreated}
        footer={<button className="btn primary" onClick={onCreated}>Listo / Ver en calendario</button>}
      >
        <div className="stack" style={{ gap: 14 }}>
          <div className="alert green">
            <span className="ai">✓</span>
            <div>
              <b style={{ fontSize: 14.5 }}>Cita reprogramada con éxito</b>
              <p style={{ margin: '4px 0 0', fontSize: 13, lineHeight: 1.4 }}>
                La cita de <b>{appointment.patient?.name ?? 'el paciente'}</b> se movió del{' '}
                <span className="mono">{formatDate(rescheduledSummary.oldIso)} {formatTime(rescheduledSummary.oldIso)}</span>{' '}
                al{' '}
                <b>{formatDate(rescheduledSummary.newIso)} a las {formatTime(rescheduledSummary.newIso)}</b>.
              </p>
            </div>
          </div>

          <p className="muted" style={{ fontSize: 13, margin: 0 }}>
            Notifica al paciente sobre su nuevo horario acordado por WhatsApp o correo:
          </p>

          <div className="row wrap" style={{ gap: 8 }}>
            <WhatsAppButton
              phone={appointment.patient?.phone}
              message={rescheduleMessage}
              label="Avisar por WhatsApp"
              onSent={() => markWhatsApp.mutate()}
            />
            <EmailButton
              email={appointment.patient?.email}
              pending={emailReminder.isPending}
              onSend={() => emailReminder.mutate()}
              label="Avisar por correo"
            />
          </div>
        </div>
      </Modal>
    );
  }

  const setQuickDate = (daysToAdd: number) => {
    const nextDate = addDays(new Date(), daysToAdd);
    setDate(format(nextDate, 'yyyy-MM-dd'));
  };

  return (
    <Modal
      title={isRescheduling ? 'Reprogramar cita' : editing ? 'Cita' : 'Nueva cita'}
      onClose={onClose}
      footer={
        isRescheduling ? (
          <>
            <button className="btn" onClick={() => setIsRescheduling(false)}>
              Volver
            </button>
            <button className="btn primary" disabled={busy} onClick={() => reschedule.mutate()}>
              {reschedule.isPending ? 'Reprogramando…' : 'Confirmar reprogramación'}
            </button>
          </>
        ) : editing ? (
          <>
            {appointment?.patient && (
              <button
                className="btn"
                style={{ marginRight: 'auto' }}
                onClick={() => openPatient(appointment.patient!.id, `atencion?appointment=${appointment.id}`)}
              >
                Atender →
              </button>
            )}
            {isScheduled && can('appointments.checkin') && (
              <button className="btn" disabled={busy} onClick={() => checkIn.mutate()}>
                {checkIn.isPending ? 'Registrando…' : 'Registrar llegada'}
              </button>
            )}
            {isScheduled && (
              <>
                <button className="btn ghost-danger" disabled={busy} onClick={() => cancel.mutate()}>
                  Cancelar cita
                </button>
                <button className="btn" disabled={busy} onClick={() => complete.mutate()}>
                  Completar
                </button>
                <button className="btn primary" disabled={busy} onClick={() => setIsRescheduling(true)}>
                  Reprogramar cita
                </button>
              </>
            )}
            {!isScheduled && <button className="btn" onClick={onClose}>Cerrar</button>}
          </>
        ) : (
          <>
            <button className="btn" onClick={onClose}>Cancelar</button>
            <button className="btn primary" disabled={!patient || busy} onClick={() => create.mutate()}>
              {create.isPending ? 'Agendando…' : 'Agendar'}
            </button>
          </>
        )
      }
    >
      <div className="stack">
        {/* Dedicated Rescheduling Sub-view */}
        {isRescheduling && appointment ? (
          <div className="stack" style={{ gap: 14 }}>
            <div className="alert" style={{ background: 'var(--surface-2)', borderColor: 'var(--border)' }}>
              <div>
                <b style={{ fontSize: 13.5 }}>{appointment.patient?.name ?? 'Paciente'}</b>
                <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-2)' }}>
                  Horario actual:{' '}
                  <b>
                    {formatDate(appointment.appointment_date)} a las {formatTime(appointment.appointment_date)}
                  </b>
                </p>
              </div>
            </div>

            <div>
              <label className="field-label" style={{ marginBottom: 6 }}>
                Atajos rápidos para la nueva fecha
              </label>
              <div className="row wrap" style={{ gap: 6 }}>
                <button type="button" className="pill" onClick={() => setQuickDate(1)}>
                  Mañana
                </button>
                <button type="button" className="pill" onClick={() => setQuickDate(2)}>
                  En 2 días
                </button>
                <button type="button" className="pill" onClick={() => setQuickDate(7)}>
                  Próxima semana
                </button>
              </div>
            </div>

            <div className="form-grid">
              <div>
                <label className="field-label">Nueva Fecha</label>
                <input className="field" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
              </div>
              <div>
                <label className="field-label">Nueva Hora</label>
                <input className="field" type="time" value={time} onChange={(e) => setTime(e.target.value)} />
              </div>
              <div>
                <label className="field-label">Duración (min)</label>
                <input
                  className="field mono"
                  inputMode="numeric"
                  value={duration}
                  onChange={(e) => setDuration(e.target.value)}
                />
              </div>
              {showDoctorSelect && (
                <div>
                  <label className="field-label">Médico</label>
                  <select className="field" value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
                    {clinicDoctors!.map((d) => (
                      <option key={d.doctor_id!} value={d.doctor_id!}>
                        {d.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </div>
            {error && <p className="field-error">{apiError(error, 'No se pudo reprogramar la cita.')}</p>}
          </div>
        ) : (
          <>
            {editing && appointment ? (
              <div className="appt-patient">
                <div className="row between">
                  <div className="row" style={{ gap: 8 }}>
                    <b style={{ fontSize: 15 }}>{appointment.patient?.name ?? 'Paciente'}</b>
                    {appointment.patient?.is_first_visit && <span className="badge accent plain">1ª visita</span>}
                  </div>
                  <StatusBadge status={appointment.status} label={appointment.status_label} />
                </div>
                {appointment.patient && (
                  <div className="row wrap muted" style={{ gap: 14, fontSize: 12.5, marginTop: 6 }}>
                    {appointment.patient.id_number && <span className="mono">CI {appointment.patient.id_number}</span>}
                    {appointment.patient.age != null && <span>{appointment.patient.age} años</span>}
                    {appointment.service_name && <span>{appointment.service_name}</span>}
                    {appointment.doctor_name && <span>{appointment.doctor_name}</span>}
                    <span>
                      Última visita:{' '}
                      {appointment.patient.last_visit_at
                        ? formatDate(appointment.patient.last_visit_at)
                        : 'sin registro'}
                    </span>
                  </div>
                )}
                {isScheduled && appointment.patient && (
                  <div className="row wrap" style={{ gap: 8, marginTop: 10, alignItems: 'center' }}>
                    <span className="muted" style={{ fontSize: 12.5 }}>
                      Recordar al paciente:
                    </span>
                    <WhatsAppButton
                      phone={appointment.patient.phone}
                      message={apptReminderMessage({
                        name: appointment.patient.name,
                        dateLabel: formatDate(appointment.appointment_date),
                        timeLabel: formatTime(appointment.appointment_date),
                        doctorName: appointment.doctor_name ?? null,
                      })}
                      label="WhatsApp"
                      onSent={() => markWhatsApp.mutate()}
                    />
                    <EmailButton
                      email={appointment.patient.email}
                      pending={emailReminder.isPending}
                      onSend={() => emailReminder.mutate()}
                      label="Email"
                    />
                  </div>
                )}
              </div>
            ) : (
              <PatientPicker selected={patient} onSelect={setPatient} />
            )}

            <div className="form-grid">
              {showDoctorSelect && (
                <div className="full">
                  <label className="field-label">Médico</label>
                  <select
                    className="field"
                    value={doctorId}
                    disabled={editing && !isScheduled}
                    onChange={(e) => setDoctorId(e.target.value)}
                  >
                    {clinicDoctors!.map((d) => (
                      <option key={d.doctor_id!} value={d.doctor_id!}>
                        {d.name}
                      </option>
                    ))}
                  </select>
                </div>
              )}
              <div>
                <label className="field-label">Fecha</label>
                <input
                  className="field"
                  type="date"
                  value={date}
                  disabled={editing && !isScheduled}
                  onChange={(e) => setDate(e.target.value)}
                />
              </div>
              <div>
                <label className="field-label">Hora</label>
                <input
                  className="field"
                  type="time"
                  value={time}
                  disabled={editing && !isScheduled}
                  onChange={(e) => setTime(e.target.value)}
                />
              </div>
              <div>
                <label className="field-label" htmlFor="appt-type">Tipo</label>
                {/* Conjunto cerrado: como texto libre, esto guardaba la etiqueta
                    en español ('Consulta') mientras el backend guardaba la clave
                    ('consultation'), así que nunca coincidían. */}
                <select
                  id="appt-type"
                  className="field"
                  value={type}
                  disabled={editing && !isScheduled}
                  onChange={(e) => setType(e.target.value)}
                >
                  {BOOKABLE_APPOINTMENT_TYPES.map((key) => (
                    <option key={key} value={key}>
                      {APPOINTMENT_TYPE_LABELS[key]}
                    </option>
                  ))}
                  {/* Una cita antigua con un valor fuera del conjunto se sigue
                      pudiendo abrir y guardar sin perder lo que decía. */}
                  {type && !BOOKABLE_APPOINTMENT_TYPES.includes(type as never) && (
                    <option value={type}>{labelFor(APPOINTMENT_TYPE_LABELS, type)}</option>
                  )}
                </select>
              </div>
              {(!editing || isScheduled) && (
                <div className="full">
                  <ServicePicker value={serviceId} onChange={setServiceId} />
                </div>
              )}
              <div>
                <label className="field-label">Duración (min)</label>
                <input
                  className="field mono"
                  inputMode="numeric"
                  value={duration}
                  disabled={editing && !isScheduled}
                  onChange={(e) => setDuration(e.target.value)}
                />
              </div>

              <div className="full" style={{ marginTop: 4 }}>
                <label className="field-label">Color de la cita</label>
                <div className="row wrap" style={{ gap: 6 }}>
                  {COLOR_SWATCHES.map((sw) => (
                    <button
                      key={sw.id}
                      type="button"
                      className={`pill ${customColor === sw.id ? 'active' : ''}`}
                      style={{
                        background: sw.bg,
                        color: sw.color,
                        fontSize: 12,
                        fontWeight: 600,
                        padding: '4px 10px',
                        border: customColor === sw.id ? `2px solid ${sw.color}` : '1px solid var(--border)',
                        cursor: 'pointer',
                      }}
                      onClick={() => setCustomColor(sw.id)}
                    >
                      {sw.label}
                    </button>
                  ))}
                </div>
              </div>

              {error && <p className="field-error full">{apiError(error, 'No se pudo guardar la cita.')}</p>}
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
