import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useSearchParams } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { resetPassword } from '@/api/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { AuthShell } from './components/AuthShell';

const schema = z
  .object({
    password: z.string().min(8, 'Usa al menos 8 caracteres'),
    password_confirmation: z.string().min(1, 'Repite la contraseña'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Las contraseñas no coinciden',
  });

type FormValues = z.infer<typeof schema>;

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [done, setDone] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      await resetPassword({
        token,
        email,
        password: values.password,
        password_confirmation: values.password_confirmation,
      });
      setDone(true);
    } catch {
      setServerError('El enlace no es válido o ya expiró. Solicita uno nuevo.');
    }
  });

  const linkIsUsable = token !== '' && email !== '';

  return (
    <AuthShell
      current="login"
      eyebrow="Acceso a Elosar"
      title="Crea una contraseña nueva."
      description="Al guardarla cerramos las sesiones abiertas en todos los dispositivos, por si alguien más tenía acceso."
      compact
    >
      <div className="auth-panel-heading">
        <span>Restablecer contraseña</span>
        <h2>Nueva contraseña</h2>
        <p>{email ? `Para la cuenta ${email}.` : 'Define la contraseña con la que entrarás a Elosar.'}</p>
      </div>

      {!linkIsUsable && (
        <>
          <div className="auth-error" role="alert">
            <strong>Enlace incompleto</strong>
            <p>Abre el enlace tal como llegó en el correo, sin recortarlo. Si lo copiaste a mano, puede faltar una parte.</p>
          </div>
          <div className="auth-secondary">
            <span>¿Necesitas otro?</span>
            <Link to="/olvide-contrasena">Pedir un enlace nuevo</Link>
          </div>
        </>
      )}

      {linkIsUsable && done && (
        <>
          <div className="auth-success" role="status">
            <strong>Contraseña actualizada</strong>
            <p>Ya puedes entrar con tu contraseña nueva. Cerramos las sesiones que estuvieran abiertas.</p>
          </div>
          <Link className="btn primary auth-submit" to="/login">
            Iniciar sesión
          </Link>
        </>
      )}

      {linkIsUsable && !done && (
        <form onSubmit={onSubmit} className="auth-form">
          <Input
            id="password"
            type={showPassword ? 'text' : 'password'}
            label="Contraseña nueva"
            autoComplete="new-password"
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
          <Input
            id="password_confirmation"
            type={showPassword ? 'text' : 'password'}
            label="Repite la contraseña"
            autoComplete="new-password"
            error={errors.password_confirmation?.message}
            {...register('password_confirmation')}
          />

          {serverError && (
            <div className="auth-error" role="alert">
              <strong>No fue posible cambiarla</strong>
              <p>{serverError}</p>
              <p>
                <Link to="/olvide-contrasena">Pedir un enlace nuevo</Link>
              </p>
            </div>
          )}

          <Button type="submit" disabled={isSubmitting} className="auth-submit">
            {isSubmitting ? 'Guardando…' : 'Guardar contraseña'}
          </Button>
        </form>
      )}

      <Link className="auth-back-link" to="/login">
        Volver a iniciar sesión
      </Link>
    </AuthShell>
  );
}
