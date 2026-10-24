import { useState, type ReactNode } from 'react';
import { birthDateProblem, EARLIEST_BIRTH_DATE } from '@/lib/birthDate';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { updatePatient } from '@/api/patients';
import { Card } from '@/components/ui/Card';
import { FormSection } from '@/components/ui/FormSection';
import { Icon } from '@/components/Icon';
import { Input } from '@/components/ui/Input';
import { CIVIL_STATUS_LABELS, DOCUMENT_COUNTRIES, DOCUMENT_TYPE_LABELS, GENDER_LABELS, labelFor } from '@/i18n/labels';
import { formatDate } from '@/i18n/es';
import { useAuthStore } from '@/store/auth';
import type { Patient } from '@/api/types';

const schema = z.object({
  name:                    z.string().min(1, 'Requerido'),
  document_type:           z.enum(['cedula', 'passport', 'foreign_id', 'other']),
  document_country:        z.string().length(2, 'Requerido'),
  id_number:               z.string().min(1, 'Requerido').max(30, 'Máx. 30'),
  birth_date:              z.string().optional().superRefine((value, ctx) => {
    const problem = birthDateProblem(value);
    if (problem) ctx.addIssue({ code: z.ZodIssueCode.custom, message: problem });
  }),
  gender:                  z.enum(['male', 'female', 'other']),
  // El <select> nativo siempre manda un valor — un paciente sin estado civil
  // registrado envía "" (la opción "— sin especificar —"), no `undefined`.
  // `.optional()` solo perdona la AUSENCIA del campo, así que sin el
  // `.or(z.literal(''))` esto rechazaba el guardado en silencio para
  // cualquier paciente que todavía no tuviera estado civil — que es la
  // mayoría, porque nadie lo llena al registrar. Mismo patrón que `email`
  // más abajo en este mismo schema.
  civil_status: z
    .enum(['single', 'married', 'common_law', 'divorced', 'widowed', 'separated'])
    .or(z.literal(''))
    .optional(),
  blood_type:              z.string().max(5).optional(),
  address:                 z.string().optional(),
  email:                   z.string().email('Correo no válido').or(z.literal('')).optional(),
  phone:                   z.string().optional(),
  emergency_contact_name:  z.string().optional(),
  emergency_contact_phone: z.string().optional(),
  insurance_provider:      z.string().optional(),
  insurance_policy_number: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

function mapPatientToForm(patient: Patient): FormValues {
  return {
    name:                    patient.name ?? '',
    document_type:           (patient.document_type as FormValues['document_type']) ?? 'cedula',
    document_country:        patient.document_country ?? 'EC',
    id_number:               patient.id_number ?? '',
    birth_date:              patient.birth_date ?? '',
    gender:                  (patient.gender as FormValues['gender']) ?? 'male',
    civil_status:            (patient.civil_status as FormValues['civil_status']) ?? undefined,
    blood_type:              patient.blood_type ?? '',
    address:                 patient.address ?? '',
    email:                   patient.email ?? '',
    phone:                   patient.phone ?? '',
    emergency_contact_name:  patient.emergency_contact_name ?? '',
    emergency_contact_phone: patient.emergency_contact_phone ?? '',
    insurance_provider:      patient.insurance_provider ?? '',
    insurance_policy_number: patient.insurance_policy_number ?? '',
  };
}

function Kv({ label, value }: { label: string; value: ReactNode }) {
  return (
    <>
      <dt>{label}</dt>
      <dd>{value || '—'}</dd>
    </>
  );
}

/**
 * "Datos generales" del expediente: se edita en la misma tarjeta, sin modal —
 * pedido explícito tras el reskin de la atención ("que se pueda escribir ahí
 * mismo, en ese cuadradito"). Reemplaza a `EditPatientModal` (único consumidor
 * que tenía).
 */
export function GeneralDataCard({ patient }: { patient: Patient }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const qc = useQueryClient();
  const [isEditing, setIsEditing] = useState(false);

  const { register, handleSubmit, watch, reset, formState } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: mapPatientToForm(patient),
  });

  const documentType = watch('document_type');
  const idLabel = DOCUMENT_TYPE_LABELS[documentType] ?? 'Documento';

  const mutation = useMutation({
    mutationFn: (values: FormValues) => updatePatient(patient.id, values),
    onSuccess: async () => {
      // Espera el refetch antes de volver a modo lectura: si se cierra primero,
      // la tarjeta muestra un instante los valores viejos.
      await qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patient.id] });
      setIsEditing(false);
    },
  });

  const apiError = mutation.isError
    ? (isAxiosError(mutation.error) &&
        (mutation.error.response?.data as { message?: string })?.message) ||
      'No se pudo guardar los cambios.'
    : null;

  // `reset()` explícito en vez de un efecto reactivo a `patient`: si se
  // dejara reactivo, un refetch en paralelo mientras el médico edita pisaría
  // lo que está escribiendo.
  const startEdit = () => {
    reset(mapPatientToForm(patient));
    setIsEditing(true);
  };
  const cancelEdit = () => {
    reset(mapPatientToForm(patient));
    setIsEditing(false);
  };

  return (
    <Card>
      <div className={`card-head flush ${isEditing ? 'editing' : ''}`.trim()}>
        <h3>Datos generales</h3>
        {isEditing ? (
          <div className="row" style={{ gap: 8 }}>
            <button className="btn sm ghost" type="button" onClick={cancelEdit} disabled={mutation.isPending}>
              Cancelar
            </button>
            <button className="btn sm primary" type="submit" form="general-data-form" disabled={mutation.isPending}>
              {mutation.isPending ? 'Guardando…' : 'Guardar'}
            </button>
          </div>
        ) : (
          <button className="btn sm ghost" type="button" onClick={startEdit}>
            <Icon name="edit" size={14} /> Editar
          </button>
        )}
      </div>

      {isEditing ? (
        <form
          id="general-data-form"
          noValidate
          className="patient-form-sections"
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FormSection title="Identidad">
            <div className="full">
              <Input
                id="general-data-name"
                label="Nombre completo *"
                error={formState.errors.name?.message}
                {...register('name')}
              />
            </div>

            <div>
              <label className="field-label" htmlFor="general-data-document-type">
                Tipo de documento *
              </label>
              <select id="general-data-document-type" className="field" {...register('document_type')}>
                {Object.entries(DOCUMENT_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="field-label" htmlFor="general-data-document-country">
                País del documento *
              </label>
              <select id="general-data-document-country" className="field" {...register('document_country')}>
                {DOCUMENT_COUNTRIES.map(({ code, name }) => (
                  <option key={code} value={code}>{name}</option>
                ))}
              </select>
            </div>

            <Input
              id="general-data-id-number"
              label={`${idLabel} *`}
              className="mono"
              maxLength={30}
              error={formState.errors.id_number?.message}
              {...register('id_number')}
            />

            <Input
              id="general-data-birth-date"
              label="Fecha de nacimiento"
              type="date"
              min={EARLIEST_BIRTH_DATE}
              max={ecuadorDateInputValue()}
              error={formState.errors.birth_date?.message}
              {...register('birth_date')}
            />

            <div>
              <label className="field-label" htmlFor="general-data-gender">Sexo *</label>
              <select id="general-data-gender" className="field" {...register('gender')}>
                <option value="male">Masculino</option>
                <option value="female">Femenino</option>
                <option value="other">Otro</option>
              </select>
            </div>

            <div>
              <label className="field-label" htmlFor="general-data-civil-status">Estado civil</label>
              <select id="general-data-civil-status" className="field" {...register('civil_status')}>
                <option value="">— sin especificar —</option>
                {Object.entries(CIVIL_STATUS_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </div>

            <Input
              id="general-data-blood-type"
              label="Tipo de sangre"
              className="mono"
              placeholder="A+, O-, …"
              maxLength={5}
              error={formState.errors.blood_type?.message}
              {...register('blood_type')}
            />
          </FormSection>

          <FormSection title="Contacto">
            <Input
              id="general-data-address"
              label="Dirección"
              placeholder="Av. Amazonas N34-120 y Av. Atahualpa"
              {...register('address')}
            />
            <Input
              id="general-data-email"
              label="Correo"
              type="email"
              error={formState.errors.email?.message}
              {...register('email')}
            />
            <Input id="general-data-phone" label="Teléfono" {...register('phone')} />
            <Input
              id="general-data-emergency-contact-name"
              label="Contacto de emergencia"
              placeholder="Nombre"
              {...register('emergency_contact_name')}
            />
            <Input
              id="general-data-emergency-contact-phone"
              label="Teléfono de emergencia"
              {...register('emergency_contact_phone')}
            />
          </FormSection>

          <FormSection title="Seguro">
            <Input id="general-data-insurance-provider" label="Aseguradora" {...register('insurance_provider')} />
            <Input
              id="general-data-insurance-policy-number"
              label="N.º de póliza"
              {...register('insurance_policy_number')}
            />
          </FormSection>

          {apiError && <p className="field-error full">{apiError}</p>}
        </form>
      ) : (
        <dl className="kv">
          <Kv label="Nombre completo" value={patient.name} />
          <Kv label="Documento" value={patient.id_number ? <span className="mono">{patient.id_number}</span> : null} />
          <Kv label="Fecha nacimiento" value={formatDate(patient.birth_date)} />
          <Kv
            label="Sexo / Sangre"
            value={[labelFor(GENDER_LABELS, patient.gender), patient.blood_type].filter(Boolean).join(' · ')}
          />
          <Kv label="Estado civil" value={labelFor(CIVIL_STATUS_LABELS, patient.civil_status)} />
          <Kv label="Teléfono" value={patient.phone} />
          <Kv label="Correo" value={patient.email} />
          {/* Dirección replaced Ciudad on the form. Patients registered before
              that only carry a city, so it is still shown — under its own label,
              because calling a city an address would be a lie. */}
          {patient.address || !patient.city ? (
            <Kv label="Dirección" value={patient.address} />
          ) : (
            <Kv label="Ciudad" value={patient.city} />
          )}
          <Kv label="Seguro" value={patient.insurance_provider} />
          <Kv
            label="N.º póliza"
            value={patient.insurance_policy_number ? <span className="mono">{patient.insurance_policy_number}</span> : null}
          />
          <Kv
            label="Contacto emergencia"
            value={[patient.emergency_contact_name, patient.emergency_contact_phone].filter(Boolean).join(' · ')}
          />
        </dl>
      )}
    </Card>
  );
}
