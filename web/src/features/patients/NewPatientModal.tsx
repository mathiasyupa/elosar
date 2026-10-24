import { useForm } from 'react-hook-form';
import { birthDateProblem, EARLIEST_BIRTH_DATE } from '@/lib/birthDate';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { createPatient } from '@/api/patients';
import { FormSection } from '@/components/ui/FormSection';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { DOCUMENT_COUNTRIES, DOCUMENT_TYPE_LABELS } from '@/i18n/labels';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import type { Patient } from '@/api/types';

const schema = z.object({
  name: z.string().min(1, 'Requerido'),
  document_type: z.enum(['cedula', 'passport', 'foreign_id', 'other']),
  document_country: z.string().length(2, 'Requerido'),
  id_number: z.string().min(1, 'Requerido').max(30, 'Máx. 30'),
  birth_date: z.string().min(1, 'Requerido').superRefine((value, ctx) => {
    const problem = birthDateProblem(value);
    if (problem) ctx.addIssue({ code: z.ZodIssueCode.custom, message: problem });
  }),
  gender: z.enum(['male', 'female', 'other']),
  address: z.string().min(1, 'Requerido'),
  email: z.string().email('Correo no válido').or(z.literal('')).optional(),
  phone: z.string().min(1, 'Requerido'),
  insurance_provider: z.string().optional(),
  insurance_policy_number: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export function NewPatientModal({ onClose, onCreated }: { onClose: () => void; onCreated: (p: Patient) => void }) {
  const { register, handleSubmit, watch, formState, setError } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { gender: 'male', document_type: 'cedula', document_country: 'EC' },
  });

  const qc = useQueryClient();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);

  const documentType = watch('document_type');
  const idLabel = DOCUMENT_TYPE_LABELS[documentType] ?? 'Documento';

  const mutation = useMutation({
    mutationFn: (values: FormValues) => createPatient(values),
    onSuccess: (patient) => {
      // The create response is the same detailed patient the expediente reads:
      // seed it so the new file opens at once, and say plainly that it worked.
      qc.setQueryData(['patient', activeWorkspaceId, patient.id], patient);
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast(`Paciente creado: ${patient.name}`);
      onCreated(patient);
    },
    onError: (error) => {
      // Put each server error on its own field («Ya existe un paciente con ese
      // documento» under the cédula), not only in a paragraph at the bottom of
      // a long form, where the doctor never saw it and thought nothing happened.
      const fieldErrors = isAxiosError(error)
        ? (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
        : undefined;
      for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
        if (field in schema.shape && messages?.[0]) {
          setError(field as keyof FormValues, { type: 'server', message: messages[0] }, { shouldFocus: true });
        }
      }
    },
  });

  const apiError = !mutation.isError
    ? null
    : isAxiosError(mutation.error) && mutation.error.code === 'ECONNABORTED'
      ? 'El servidor tardó demasiado en responder. Antes de volver a intentar, busca al paciente en la lista: puede que ya se haya creado.'
      : isAxiosError(mutation.error) && !mutation.error.response
        ? 'No hay conexión con el servidor. Revisa tu internet y vuelve a intentar.'
        : (isAxiosError(mutation.error) && (mutation.error.response?.data as { message?: string })?.message) ||
          'No se pudo crear el paciente.';

  return (
    <Modal
      title="Nuevo paciente"
      size="lg"
      onClose={onClose}
      pending={mutation.isPending}
      footer={
        <>
          <button
            className="btn"
            type="button"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            Cancelar
          </button>
          <button className="btn primary" form="new-patient" type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Guardando…' : 'Crear paciente'}
          </button>
        </>
      }
    >
      <form
        id="new-patient"
        noValidate
        className="patient-form-sections patient-form-compact"
        onSubmit={handleSubmit((values) => mutation.mutate(values))}
      >
        {apiError && (
          <div className="alert red full" role="alert">
            <div><b>No se guardó el paciente</b><p>{apiError}</p></div>
          </div>
        )}
        <FormSection title="Identidad">
          <div className="full">
            <Input
              id="new-patient-name"
              label="Nombre completo *"
              error={formState.errors.name?.message}
              {...register('name')}
            />
          </div>
          <div>
            <label className="field-label" htmlFor="new-patient-document-type">
              Tipo de documento *
            </label>
            <select
              id="new-patient-document-type"
              className="field"
              aria-invalid={formState.errors.document_type ? true : undefined}
              aria-describedby={
                formState.errors.document_type ? 'new-patient-document-type-error' : undefined
              }
              {...register('document_type')}
            >
              {Object.entries(DOCUMENT_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
            {formState.errors.document_type && (
              <p className="field-error mono" id="new-patient-document-type-error">
                {formState.errors.document_type.message}
              </p>
            )}
          </div>
          <div>
            <label className="field-label" htmlFor="new-patient-document-country">
              País del documento *
            </label>
            <select
              id="new-patient-document-country"
              className="field"
              aria-invalid={formState.errors.document_country ? true : undefined}
              aria-describedby={
                formState.errors.document_country ? 'new-patient-document-country-error' : undefined
              }
              {...register('document_country')}
            >
              {DOCUMENT_COUNTRIES.map(({ code, name }) => (
                <option key={code} value={code}>
                  {name}
                </option>
              ))}
            </select>
            {formState.errors.document_country && (
              <p className="field-error mono" id="new-patient-document-country-error">
                {formState.errors.document_country.message}
              </p>
            )}
          </div>
          <Input
            id="new-patient-id-number"
            label={`${idLabel} *`}
            className="mono"
            maxLength={30}
            error={formState.errors.id_number?.message}
            {...register('id_number')}
          />
          <Input
            id="new-patient-birth-date"
            label="Fecha de nacimiento *"
            type="date"
            min={EARLIEST_BIRTH_DATE}
            max={ecuadorDateInputValue()}
            error={formState.errors.birth_date?.message}
            {...register('birth_date')}
          />
          <div>
            <label className="field-label" htmlFor="new-patient-gender">Sexo *</label>
            <select
              id="new-patient-gender"
              className="field"
              aria-invalid={formState.errors.gender ? true : undefined}
              aria-describedby={
                formState.errors.gender ? 'new-patient-gender-error' : undefined
              }
              {...register('gender')}
            >
              <option value="male">Masculino</option>
              <option value="female">Femenino</option>
              <option value="other">Otro</option>
            </select>
            {formState.errors.gender && (
              <p className="field-error mono" id="new-patient-gender-error">
                {formState.errors.gender.message}
              </p>
            )}
          </div>
        </FormSection>

        <FormSection title="Contacto">
          <Input
            id="new-patient-address"
            label="Dirección *"
            placeholder="Av. Amazonas N34-120 y Av. Atahualpa"
            error={formState.errors.address?.message}
            {...register('address')}
          />
          <Input
            id="new-patient-email"
            label="Correo"
            error={formState.errors.email?.message}
            {...register('email')}
          />
          <Input
            id="new-patient-phone"
            label="Teléfono *"
            error={formState.errors.phone?.message}
            {...register('phone')}
          />
        </FormSection>

        <FormSection title="Seguro">
          <Input
            id="new-patient-insurance-provider"
            label="Aseguradora"
            {...register('insurance_provider')}
          />
          <Input
            id="new-patient-insurance-policy-number"
            label="N.º de póliza"
            {...register('insurance_policy_number')}
          />
        </FormSection>

      </form>
    </Modal>
  );
}
