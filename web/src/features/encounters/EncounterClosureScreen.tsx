import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { NewInvoiceModal } from '@/features/invoices/NewInvoiceModal';
import { AppointmentModal } from '@/features/agenda/NewAppointmentModal';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { formatDate } from '@/i18n/es';
import type { BillableService, BillingPatient, Patient } from '@/api/types';

interface Props {
  patient: Patient;
  /** Shown as the closing summary; empty means it was closed with override. */
  diagnosis: string;
  /** Appointment the saved record ended up linked to, for the invoice. */
  completedAppointmentId: number | null;
  /** What was just declared as performed, to open the invoice already filled. */
  services: BillableService[];
  /** Control the doctor indicated, if any — the date to book the next visit on. */
  followUpDate: string | null;
  /** Jump to the next ready patient in the queue (clinic focus mode). */
  onAttendNext: () => void;
}

/**
 * What happens once the visit is saved.
 *
 * The previous version offered up to six buttons of equal weight, which read as
 * six pending steps rather than one finished visit. Reported in first real use:
 * "the only one that works is Emitir factura" — the doctor believed leaving by
 * any other button would lose the consultation. Nothing was ever lost; the
 * record is written before this screen renders.
 *
 * So: one primary action, and it is *finishing*. Billing and booking are
 * offered, plainly optional, underneath. What "finishing" means depends on
 * where you work — back to the queue in a clinic, back to the patient in a solo
 * consultorio — but it is always one obvious way out.
 */
export function EncounterClosureScreen({
  patient,
  diagnosis,
  completedAppointmentId,
  services,
  followUpDate,
  onAttendNext,
}: Props) {
  const [showCharge, setShowCharge] = useState(false);
  const [showBooking, setShowBooking] = useState(false);
  const [booked, setBooked] = useState(false);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const doctor = useAuthStore((s) => s.doctor);
  const { isClinic, can } = useWorkspaceCapabilities();

  const billingPatient: BillingPatient = {
    id: patient.id,
    name: patient.name,
    id_number: patient.id_number,
  };

  const mayBill = can('billing.manage');
  const mayBook = can('appointments.manage');
  const pendingControl = followUpDate && mayBook && !booked;

  return (
    <>
      <div className="close-screen">
        <Card>
          <span className="cs-check" aria-hidden="true">
            <Icon name="check" size={26} strokeWidth={2.4} />
          </span>
          <h2 style={{ marginTop: 12 }}>Visita de {patient.name} terminada</h2>
          <p className="muted cs-dx">
            {diagnosis.trim() ? diagnosis : 'Cerrada sin diagnóstico completo (override).'}
          </p>

          {/* Lo que más ansiedad causaba: no quedaba claro que ya estaba hecho. */}
          <p className="cs-saved">
            <Icon name="check" size={14} />
            Ya está guardada en la historia del paciente. Lo de abajo es opcional.
          </p>

          {/* Informativo, no opcional: quien no puede cobrar necesita saber a
              dónde fue su atención. Vivía dentro del bloque de acciones
              opcionales, que no se renderiza sin ninguna acción disponible —
              justo el caso de este médico. */}
          {!mayBill && can('billing.view') && isClinic && (
            <p className="muted cs-note cs-handoff">
              <Icon name="alert" size={14} />
              La atención quedó pendiente de facturar en caja.
            </p>
          )}

          <div className="cs-primary">
            {isClinic ? (
              <>
                <button className="btn primary" type="button" onClick={onAttendNext}>
                  <Icon name="steth" size={16} /> Atender al siguiente
                </button>
                <button className="btn" type="button" onClick={() => navigate('/cola')}>
                  Volver a la cola
                </button>
              </>
            ) : (
              <>
                <button className="btn primary" type="button" onClick={() => navigate('/agenda')}>
                  <Icon name="cal" size={16} /> Volver a la agenda
                </button>
                <PatientWorkspaceLink
                  className="btn"
                  patientId={patient.id}
                  section="historial"
                >
                  Ver la historia de {patient.name.split(' ')[0]}
                </PatientWorkspaceLink>
              </>
            )}
          </div>

          {(pendingControl || mayBill) && (
            <div className="cs-optional">
              <h5>Antes de irte, si quieres</h5>

              {pendingControl && (
                <button className="cs-opt" type="button" onClick={() => setShowBooking(true)}>
                  <Icon name="cal" size={16} />
                  <span>
                    <b>Agendar el control del {formatDate(followUpDate)}</b>
                    <span className="muted">
                      Si no, {patient.name.split(' ')[0]} queda en Pacientes → Seguimiento para llamarle
                    </span>
                  </span>
                </button>
              )}

              {booked && (
                <p className="cs-done">
                  <Icon name="check" size={14} /> Control agendado.
                </p>
              )}

              {mayBill && (
                <button className="cs-opt" type="button" onClick={() => setShowCharge(true)}>
                  <Icon name="invoice" size={16} />
                  <span>
                    <b>Emitir la factura</b>
                    <span className="muted">
                      {services.length > 0
                        ? `${services.length} servicio(s) declarado(s), ya cargados`
                        : 'También puedes hacerlo después desde Facturación'}
                    </span>
                  </span>
                </button>
              )}

            </div>
          )}
        </Card>
      </div>

      {showBooking && followUpDate && (
        <AppointmentModal
          defaultPatient={patient}
          defaultDate={followUpDate}
          defaultType="control"
          onClose={() => setShowBooking(false)}
          onCreated={() => {
            setShowBooking(false);
            setBooked(true);
            qc.invalidateQueries({ queryKey: ['appointments'] });
            qc.invalidateQueries({ queryKey: ['pending-follow-ups'] });
          }}
        />
      )}

      {showCharge && (
        <NewInvoiceModal
          initialPatient={billingPatient}
          initialServices={services}
          appointmentId={completedAppointmentId}
          attendingDoctorId={doctor?.id ?? null}
          attendingDoctorName={doctor?.name ?? null}
          onClose={() => setShowCharge(false)}
          onCreated={(invoice) => {
            setShowCharge(false);
            qc.invalidateQueries({ queryKey: ['billables'] });
            navigate(`/facturas/${invoice.id}`);
          }}
        />
      )}
    </>
  );
}
