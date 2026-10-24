export interface DashboardAppointmentView {
  id: number;
  appointmentDate: string | null;
  status: string;
  statusLabel: string;
  type?: string | null;
  patient?: {
    id: number;
    name: string;
    idNumber: string | null;
    isFirstVisit?: boolean | null;
  };
}

interface DashboardAppointmentSource {
  id: number;
  appointment_date: string | null;
  status: string;
  status_label: string;
  type?: string | null;
  patient?: {
    id: number;
    name: string;
    id_number: string | null;
    is_first_visit?: boolean | null;
  } | null;
}

export function toDashboardAppointmentView(
  appointment: DashboardAppointmentSource,
): DashboardAppointmentView {
  return {
    id: appointment.id,
    appointmentDate: appointment.appointment_date,
    status: appointment.status,
    statusLabel: appointment.status_label,
    type: appointment.type,
    patient: appointment.patient
      ? {
          id: appointment.patient.id,
          name: appointment.patient.name,
          idNumber: appointment.patient.id_number,
          isFirstVisit: appointment.patient.is_first_visit,
        }
      : undefined,
  };
}
