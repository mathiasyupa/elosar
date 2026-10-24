import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  exchangeGoogleCode,
  isPendingVerification,
  needsRegistration,
  registerClinicAdmin,
} from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { TermsConsent } from './TermsConsent';
import { AuthShell } from './components/AuthShell';
import { getSafeRegistrationError, isUsableAuthResponse } from './authExperience';

const baseSchema = {
  clinic_name: z.string().min(1, 'Ingresa el nombre de la clínica'),
  name: z.string().min(1, 'Ingresa tu nombre'),
  email: z.string().email('Correo no válido'),
  phone: z.string().optional(),
  // Mirrors the server rule; the account is not created without it.
  accept_terms: z.boolean().refine((value) => value === true, {
    message: 'Debes aceptar los términos para crear la cuenta',
  }),
};

const schema = z.object({ ...baseSchema, password: z.string().min(8, 'Mínimo 8 caracteres') });
// Signing up through Google: no password to set, and the address is fixed.
const googleSchema = z.object({ ...baseSchema, password: z.string().optional() });

type FormValues = z.infer<typeof googleSchema>;

export function ClinicAdminRegisterPage() {
  const navigate = useNavigate();
  const sessionToken = useAuthStore((state) => state.token);
  const setSession = useAuthStore((state) => state.setSession);
  const [params] = useSearchParams();
  const googleCode = params.get('google');
  const [serverError, setServerError] = useState<string | null>(null);

  // Not consumed by this call — a handoff with no account behind it survives the
  // exchange so this screen can read the identity it vouches for.
  const { data: googleIdentity } = useQuery({
    queryKey: ['google-exchange', googleCode],
    queryFn: () => exchangeGoogleCode(googleCode as string),
    enabled: Boolean(googleCode),
    retry: false,
  });

  const {
    register,
    handleSubmit,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(googleCode ? googleSchema : schema) });

  const googleEmail =
    googleIdentity && needsRegistration(googleIdentity) ? googleIdentity.email : null;

  useEffect(() => {
    if (!googleIdentity || !needsRegistration(googleIdentity)) return;
    setValue('email', googleIdentity.email);
    if (googleIdentity.name) setValue('name', googleIdentity.name);
  }, [googleIdentity, setValue]);

  if (sessionToken) return <Navigate to="/dashboard" replace />;

  const onSubmit = handleSubmit(async ({ password, ...values }) => {
    setServerError(null);
    try {
      const response = googleCode
        ? await registerClinicAdmin({ ...values, google_code: googleCode })
        : await registerClinicAdmin({ ...values, password: password as string });

      // Without Google, no session comes back on purpose — see DoctorRegisterPage.
      if (isPendingVerification(response)) {
        navigate(`/verifica-tu-correo?email=${encodeURIComponent(response.email)}`, { replace: true });
        return;
      }

      if (!isUsableAuthResponse(response)) {
        setServerError(getSafeRegistrationError());
        return;
      }
      setSession({
        token: response.token,
        user: response.user,
        doctor: response.doctor,
        staffProfile: response.staff_profile,
        workspaces: response.workspaces,
      });
      navigate('/dashboard', { replace: true });
    } catch {
      setServerError(getSafeRegistrationError());
    }
  });

  return (
    <AuthShell
      current="register"
      eyebrow="Crea tu cuenta"
      title="Crear espacio de clínica."
      description="Crea un entorno para coordinar sede, equipo y operación. Esta ruta crea una cuenta administrativa; no crea un perfil médico."
    >
      <div className="auth-panel-heading">
        <span>Administración de clínica</span>
        <h2>Configura la organización</h2>
        <p>Después podrás invitar a médicos y personal con responsabilidades separadas.</p>
      </div>

      <form onSubmit={onSubmit} className="auth-form">
        <Input
          id="clinic_name"
          label="Nombre de la clínica"
          placeholder="Centro Médico Santa María"
          autoComplete="organization"
          error={errors.clinic_name?.message}
          {...register('clinic_name')}
        />
        <Input id="name" label="Tu nombre" autoComplete="name" error={errors.name?.message} {...register('name')} />
        {googleEmail ? (
          // Fixed by the handoff: the server checks it, so editing it here could
          // only produce a rejection.
          <div className="auth-google-locked" role="status">
            <strong>Entrarás con Google</strong>
            <p>
              <b>{googleEmail}</b>
            </p>
          </div>
        ) : (
          <Input
            id="email"
            type="email"
            label="Correo electrónico"
            autoComplete="email"
            error={errors.email?.message}
            {...register('email')}
          />
        )}
        <Input id="phone" type="tel" inputMode="tel" autoComplete="tel" label="Teléfono (opcional)" error={errors.phone?.message} {...register('phone')} />
        {!googleCode && (
          <Input
            id="password"
            type="password"
            label="Contraseña"
            autoComplete="new-password"
            error={errors.password?.message}
            {...register('password')}
          />
        )}

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
          {isSubmitting ? 'Creando espacio…' : 'Crear espacio de clínica'}
        </Button>
      </form>

      <p className="auth-panel-footer">
        ¿Tu acceso es para consultorio? <Link to="/crear-cuenta/medico">Usa el alta médica</Link>
      </p>
    </AuthShell>
  );
}
