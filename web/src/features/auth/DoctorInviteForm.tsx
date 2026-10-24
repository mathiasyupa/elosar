import { useState } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { registerDoctorFromInvite } from '@/api/auth';
import { fetchSpecialties } from '@/api/catalogs';
import { useAuthStore } from '@/store/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { TermsConsent } from './TermsConsent';
import { StepSpecialty } from './register-wizard/StepSpecialty';
import { getSafeRegistrationError, isUsableAuthResponse } from './authExperience';

// Clinical fields the server requires to create a real Doctor (mirror of
// POST /auth/register). Email comes from the invitation, not the form.
const schema = z.object({
  name: z.string().min(1, 'Ingresa tu nombre completo'),
  id_number: z.string().length(10, 'La cédula tiene 10 dígitos'),
  phone: z.string().min(7, 'Ingresa un teléfono válido'),
  city: z.string().min(1, 'Ingresa tu ciudad'),
  password: z.string().min(8, 'Mínimo 8 caracteres'),
  // First entry is the primary specialty (printed on prescriptions).
  specialty_ids: z.array(z.number().int().positive()).min(1, 'Elige al menos una especialidad'),
  // An invitation proves the address, not agreement to Elosar's terms.
  accept_terms: z.boolean().refine((value) => value === true, {
    message: 'Debes aceptar los términos para crear la cuenta',
  }),
});

type FormValues = z.infer<typeof schema>;

/**
 * Onboarding form for a doctor invited by email (invite.role === 'doctor').
 * Collects the clinical fields (cédula, specialty, …) so the server creates a
 * real Doctor + personal workspace and joins the inviting clinic as a doctor.
 */
export function DoctorInviteForm({ token }: { token: string }) {
  const navigate = useNavigate();
  const setSession = useAuthStore((s) => s.setSession);
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { specialty_ids: [] },
  });
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = form;

  const { data: specialties, isLoading: loadingSpecialties } = useQuery({
    queryKey: ['specialties'],
    queryFn: fetchSpecialties,
  });

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      const response = await registerDoctorFromInvite({
        token,
        name: values.name,
        password: values.password,
        id_number: values.id_number,
        phone: values.phone,
        city: values.city,
        specialty_ids: values.specialty_ids,
        accept_terms: values.accept_terms,
      });
      if (!isUsableAuthResponse(response)) {
        setServerError(getSafeRegistrationError());
        return;
      }
      const { token: authToken, user, doctor, staff_profile, workspaces } = response;
      setSession({ token: authToken, user, doctor, staffProfile: staff_profile, workspaces });
      navigate('/dashboard', { replace: true });
    } catch {
      setServerError(getSafeRegistrationError());
    }
  });

  return (
    <FormProvider {...form}>
      <form onSubmit={onSubmit} className="auth-form">
        <Input id="name" label="Nombre completo" autoComplete="name" error={errors.name?.message} {...register('name')} />
        <div className="auth-field-pair">
          <Input id="id_number" label="Cédula" error={errors.id_number?.message} {...register('id_number')} />
          <Input id="phone" type="tel" inputMode="tel" autoComplete="tel" label="Teléfono" error={errors.phone?.message} {...register('phone')} />
        </div>
        <Input id="city" autoComplete="address-level2" label="Ciudad" error={errors.city?.message} {...register('city')} />
        <Input
          id="password"
          type="password"
          label="Contraseña"
          autoComplete="new-password"
          error={errors.password?.message}
          {...register('password')}
        />

        <StepSpecialty specialties={specialties} loadingSpecialties={loadingSpecialties} />

        {serverError && (
          <div className="auth-error" role="alert">
            <strong>No pudimos completar el alta</strong>
            <p>{serverError}</p>
          </div>
        )}

        <TermsConsent error={errors.accept_terms?.message}>
          <input type="checkbox" {...register('accept_terms')} />
        </TermsConsent>

        <Button type="submit" className="auth-submit" disabled={isSubmitting}>
          {isSubmitting ? 'Activando acceso…' : 'Aceptar invitación y entrar'}
        </Button>
      </form>
    </FormProvider>
  );
}
