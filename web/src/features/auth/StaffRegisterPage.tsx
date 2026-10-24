import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { fetchInvite, registerStaff } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { ROLE_LABELS } from '@/lib/capabilities';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { TermsConsent } from './TermsConsent';
import { AuthShell } from './components/AuthShell';
import { DoctorInviteForm } from './DoctorInviteForm';
import { getSafeRegistrationError, isUsableAuthResponse } from './authExperience';

const schema = z.object({
  name: z.string().min(1, 'Ingresa tu nombre'),
  phone: z.string().optional(),
  password: z.string().min(8, 'Mínimo 8 caracteres'),
  // An invitation proves the address, not agreement to Elosar's terms.
  accept_terms: z.boolean().refine((value) => value === true, {
    message: 'Debes aceptar los términos para crear la cuenta',
  }),
});

type FormValues = z.infer<typeof schema>;

export function StaffRegisterPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const navigate = useNavigate();
  const sessionToken = useAuthStore((state) => state.token);
  const setSession = useAuthStore((state) => state.setSession);
  const [serverError, setServerError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const { data: invite, isLoading, isError } = useQuery({
    queryKey: ['invite', token],
    queryFn: () => fetchInvite(token),
    enabled: token.length > 0,
    retry: false,
  });

  if (sessionToken) return <Navigate to="/dashboard" replace />;

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      const response = await registerStaff({ token, ...values });
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

  const invalidInvite = !token || isError;

  return (
    <AuthShell
      current="register"
      eyebrow="Invitación de clínica"
      title="Acceso por invitación."
      description="Este flujo incorpora a una persona al espacio de una clínica existente. El rol y la organización vienen definidos en el enlace."
      compact
    >
      <div className="auth-panel-heading">
        <span>Incorporación de equipo</span>
        <h2>{invite ? `Únete a ${invite.clinic_name}` : 'Validar invitación'}</h2>
        <p>
          {invite
            ? `${invite.email} · ${ROLE_LABELS[invite.role]}`
            : 'Comprobamos el enlace antes de solicitar tus datos.'}
        </p>
      </div>

      {invalidInvite ? (
        <div className="auth-error" role="alert">
          <strong>Enlace no disponible</strong>
          <p>Esta invitación no es válida o ya fue utilizada. Pide a la clínica un nuevo enlace.</p>
        </div>
      ) : isLoading ? (
        <div className="auth-loading" aria-busy="true">
          <Spinner label="Validando invitación…" />
        </div>
      ) : invite?.role === 'doctor' ? (
        <DoctorInviteForm token={token} />
      ) : (
        <form onSubmit={onSubmit} className="auth-form">
          <Input id="name" label="Nombre completo" autoComplete="name" error={errors.name?.message} {...register('name')} />
          <Input id="phone" type="tel" inputMode="tel" autoComplete="tel" label="Teléfono (opcional)" error={errors.phone?.message} {...register('phone')} />
          <Input
            id="password"
            type="password"
            label="Contraseña"
            autoComplete="new-password"
            error={errors.password?.message}
            {...register('password')}
          />

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
      )}
    </AuthShell>
  );
}
