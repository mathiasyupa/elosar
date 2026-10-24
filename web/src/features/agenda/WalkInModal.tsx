import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { createWalkInAppointment } from '@/api/appointments';
import { PatientPicker } from '@/components/PatientPicker';
import { Modal } from '@/components/ui/Modal';
import type { Patient, TeamMember } from '@/api/types';

interface Props {
  doctors: TeamMember[];
  onClose: () => void;
  onCreated: () => void;
}

function apiError(error: unknown, fallback: string): string {
  if (isAxiosError(error)) return (error.response?.data as { message?: string })?.message ?? fallback;
  return fallback;
}

/** Walk-in: patient arrives without booking — creates appointment already checked in. */
export function WalkInModal({ doctors, onClose, onCreated }: Props) {
  const [mode, setMode] = useState<'existing' | 'new'>('existing');
  const [patient, setPatient] = useState<Patient | null>(null);
  const [name, setName] = useState('');
  const [idNumber, setIdNumber] = useState('');
  const [doctorId, setDoctorId] = useState(String(doctors[0]?.doctor_id ?? ''));

  const walkIn = useMutation({
    mutationFn: () =>
      createWalkInAppointment({
        doctor_id: Number(doctorId),
        ...(mode === 'existing'
          ? { patient_id: patient!.id }
          : { name: name.trim(), id_number: idNumber.trim() }),
      }),
    onSuccess: onCreated,
  });

  const canSubmit =
    doctorId &&
    (mode === 'existing' ? patient : name.trim() && idNumber.trim());

  return (
    <Modal
      title="Llegada sin cita"
      onClose={onClose}
      footer={
        <>
          <button className="btn" onClick={onClose}>Cancelar</button>
          <button className="btn primary" disabled={!canSubmit || walkIn.isPending} onClick={() => walkIn.mutate()}>
            {walkIn.isPending ? 'Registrando…' : 'Registrar y enviar a cola'}
          </button>
        </>
      }
    >
      <div className="stack">
        <p className="muted" style={{ fontSize: 13 }}>
          Crea la cita ahora y deja al paciente en sala (registro de llegada automático) para tomarle los signos vitales.
        </p>

        <div>
          <label className="field-label">Médico</label>
          <select className="field" value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
            {doctors.map((d) => (
              <option key={d.doctor_id!} value={d.doctor_id!}>{d.name}</option>
            ))}
          </select>
        </div>

        <div className="pill-row">
          <button type="button" className={`pill ${mode === 'existing' ? 'active' : ''}`} onClick={() => setMode('existing')}>
            Paciente existente
          </button>
          <button type="button" className={`pill ${mode === 'new' ? 'active' : ''}`} onClick={() => setMode('new')}>
            Nuevo (mínimo)
          </button>
        </div>

        {mode === 'existing' ? (
          <PatientPicker selected={patient} onSelect={setPatient} />
        ) : (
          <div className="form-grid">
            <div>
              <label className="field-label">Nombre</label>
              <input className="field" value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div>
              <label className="field-label">Cédula / ID</label>
              <input className="field" value={idNumber} onChange={(e) => setIdNumber(e.target.value)} />
            </div>
          </div>
        )}

        {walkIn.isError && (
          <p className="field-error">{apiError(walkIn.error, 'No se pudo registrar la llegada sin cita.')}</p>
        )}
      </div>
    </Modal>
  );
}
