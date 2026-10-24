import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { safeNextPath } from './nextPath';
import { Icon } from '@/components/Icon';
import { login } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { AuthShell } from './components/AuthShell';
import { GoogleButton } from './components/GoogleButton';
import { isUsableAuthResponse, requiresTermsAcceptance } from './authExperience';
import { forgetRecentDoctors, recentDoctors } from './recentDoctors';

const schema = z.object({
  email: z.string().email('Ingresa un correo válido'),
  password: z.string().min(1, 'Ingresa tu contraseña'),
});

type FormValues = z.infer<typeof schema>;

export function LoginPage() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const verificacion = params.get('verificacion');
  const claveActualizada = params.get('clave') === 'actualizada';
  // Back to the page the session ended on (ProtectedRoute sets ?next=).
  const next = safeNextPath(params.get('next'));
  const token = useAuthStore((state) => state.token);
  const setSession = useAuthStore((state) => state.setSession);
  const [serverError, setServerError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { email: params.get('email') ?? '' } });

  if (token) return <Navigate to={next ?? '/dashboard'} replace />;

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      const response = await login(values.email, values.password);
      if (!isUsableAuthResponse(response) && !requiresTermsAcceptance(response.legal) && !response.legal.password_change_required) {
        setServerError('Tu cuenta no tiene un espacio de trabajo disponible. Contacta al equipo de Elosar.');
        return;
      }
      const { token: authToken, user, doctor, staff_profile, workspaces } = response;
      setSession({ token: authToken, user, doctor, staffProfile: staff_profile, workspaces });
      useAuthStore.getState().setLegal(response.legal);
      navigate(
        response.legal.password_change_required
          ? '/cambiar-clave-temporal'
          : response.legal.terms_acceptance_required ? '/aceptar-terminos' : next ?? '/dashboard',
        { replace: true },
      );
    } catch (error) {
      // The password was right but the address was never confirmed. Send them
      // somewhere they can act, instead of a dead end that reads as "wrong
      // password" and makes them try again forever.
      const failure = error as { response?: { status?: number; data?: { code?: string } } };
      if (failure.response?.status === 403 && failure.response.data?.code === 'email_unverified') {
        navigate(`/verifica-tu-correo?email=${encodeURIComponent(values.email)}`, { replace: true });
        return;
      }
      setServerError('No pudimos iniciar sesión con esos datos.');
    }
  });

  return (
    <AuthShell
      current="login"
      eyebrow="Acceso a Elosar"
      title="Accede a tu espacio."
      description="Continúa donde quedó tu práctica: agenda, pacientes, consulta y operación en un mismo entorno."
      compact
    >
      <div className="auth-panel-heading">
        <span>Usuario incorporado</span>
        <h2>Iniciar sesión</h2>
        <p>Utiliza el correo con el que recibiste acceso.</p>
      </div>

      {/* Where the confirmation link lands. Expiry is the ordinary case, not an
          attack, so it gets a way forward rather than a dead end. */}
      {verificacion === 'ok' && (
        <div className="auth-success" role="status">
          <strong>Correo confirmado</strong>
          <p>Tu cuenta ya está activa. Inicia sesión para entrar.</p>
        </div>
      )}
      {claveActualizada && (
        <div className="auth-success" role="status">
          <strong>Contraseña actualizada</strong>
          <p>Inicia sesión con tu contraseña nueva para continuar.</p>
        </div>
      )}
      {(verificacion === 'expirada' || verificacion === 'invalida') && (
        <div className="auth-error" role="alert">
          <strong>Ese enlace ya no sirve</strong>
          <p>
            Los enlaces de confirmación caducan. Inicia sesión y te llevamos a pedir uno nuevo.
          </p>
        </div>
      )}

      {/* Above the form, not below it: for anyone who signed up with Google this
          is the way in, and burying it under the password fields sends them to
          type a password they never set. */}
      <GoogleButton label="Iniciar sesión con Google" />
      <div className="auth-divider">o con tu correo</div>

      <form onSubmit={onSubmit} className="auth-form">
        {recentDoctors().length > 0 && (
          <div className="recent-doctors">
            <span className="muted">Médicos vistos en este equipo</span>
            {recentDoctors().map((doctor) => <button className="recent-doctor" type="button" key={doctor.email} onClick={() => window.location.assign(`/login?email=${encodeURIComponent(doctor.email)}`)}>{doctor.photo ? <img src={doctor.photo} alt="" /> : <span>{doctor.name.slice(0, 1)}</span>} {doctor.name}</button>)}
            <button type="button" className="btn ghost sm" onClick={() => { forgetRecentDoctors(); window.location.reload(); }}>Olvidar médicos</button>
          </div>
        )}
        <Input
          id="email"
          type="email"
          label="Correo electrónico"
          autoComplete="username"
          error={errors.email?.message}
          {...register('email')}
        />
        <Input
          id="password"
          type={showPassword ? 'text' : 'password'}
          label="Contraseña"
          autoComplete="current-password"
          error={errors.password?.message}
          trailingAction={
            <button
              type="button"
              className="password-toggle"
              aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              aria-pressed={showPassword}
              title={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              onClick={() => setShowPassword((visible) => !visible)}
            >
              <Icon name={showPassword ? 'eyeOff' : 'eye'} size={18} />
            </button>
          }
          {...register('password')}
        />

        <Link className="auth-form-aside" to="/olvide-contrasena">
          ¿Olvidaste tu contraseña?
        </Link>

        {serverError && (
          <div className="auth-error" role="alert">
            <strong>No fue posible entrar</strong>
            <p>{serverError}</p>
          </div>
        )}

        <Button type="submit" disabled={isSubmitting} className="auth-submit">
          {isSubmitting ? 'Iniciando sesión…' : 'Entrar a Elosar'}
        </Button>
      </form>

      <div className="auth-secondary">
        <span>¿Todavía no tienes una cuenta?</span>
        <Link to="/crear-cuenta">Crear cuenta</Link>
      </div>
      <Link className="auth-back-link" to="/">
        Volver al producto
      </Link>
    </AuthShell>
  );
}
