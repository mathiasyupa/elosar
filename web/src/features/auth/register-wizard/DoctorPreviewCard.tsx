import { useFormContext, useWatch } from 'react-hook-form';
import type { Specialty } from '@/api/types';
import type { RegisterFormValues } from './schema';

interface DoctorPreviewCardProps {
  specialties?: Specialty[];
}

function initialsFor(name: string) {
  const initials = name
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

  return initials || 'DR';
}

export function DoctorPreviewCard({ specialties = [] }: DoctorPreviewCardProps) {
  const { control } = useFormContext<RegisterFormValues>();
  const values = useWatch({ control });
  const name = values.name?.trim() || 'Tu nombre profesional';
  const selectedSpecialties = (values.specialty_ids ?? []).flatMap((id) => {
    const specialty = specialties.find((item) => item.id === id);
    return specialty ? [specialty] : [];
  });

  return (
    <section className="doctor-preview" aria-labelledby="doctor-preview-title">
      <div className="doctor-preview-heading">
        <span>Perfil médico</span>
        <p id="doctor-preview-title">Vista previa de tu perfil</p>
      </div>

      <div className="doctor-preview-identity">
        <span className="doctor-preview-avatar" aria-hidden="true">{initialsFor(values.name ?? '')}</span>
        <div>
          <strong>{name}</strong>
          {selectedSpecialties.length > 0 ? (
            <p>{selectedSpecialties.map((specialty) => specialty.name).join(' · ')}</p>
          ) : (
            <p>Tus especialidades aparecerán aquí.</p>
          )}
        </div>
      </div>

      <dl className="doctor-preview-details">
        <div>
          <dt>Ciudad</dt>
          <dd>{values.city?.trim() || 'Pendiente de completar'}</dd>
        </div>
        <div>
          <dt>Cédula</dt>
          <dd>{values.id_number?.trim() || 'Pendiente de completar'}</dd>
        </div>
        <div>
          <dt>Correo</dt>
          <dd>{values.email?.trim() || 'Pendiente de completar'}</dd>
        </div>
      </dl>

      <p className="doctor-preview-note">Esta vista cambia mientras completas tus datos.</p>
    </section>
  );
}
