import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { changeTemporaryPassword } from '@/api/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useAuthStore } from '@/store/auth';
import { AuthShell } from './components/AuthShell';

const schema = z.object({
  current_password: z.string().min(1, 'Ingresa la clave temporal'),
  password: z.string().min(8, 'Usa al menos 8 caracteres'),
  password_confirmation: z.string().min(1, 'Repite la contraseña nueva'),
}).refine((values) => values.password === values.password_confirmation, {
  path: ['password_confirmation'],
  message: 'Las contraseñas no coinciden',
});

type FormValues = z.infer<typeof schema>;

export function ChangeTemporaryPasswordPage() {
  const token = useAuthStore((state) => state.token);
  const changeRequired = useAuthStore((state) => state.legal?.password_change_required);
  const clearSession = useAuthStore((state) => state.logout);
  const navigate = useNavigate();
  const [serverError, setServerError] = useState<string | null>(null);
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
  });

  if (!token) return <Navigate to="/login" replace />;
  if (!changeRequired) return <Navigate to="/dashboard" replace />;

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      await changeTemporaryPassword(values);
      // The server has revoked every token, including this one. Drop the
      // persisted copy before navigating so no protected route reuses it.
      clearSession();
      navigate('/login?clave=actualizada', { replace: true });
    } catch {
      setServerError('No pudimos cambiarla. Comprueba la clave temporal e inténtalo otra vez.');
    }
  });

  return (
    <AuthShell
      current="login"
      eyebrow="Protege tu cuenta"
      title="Crea tu contraseña personal."
      description="Tu cuenta se creó con una clave temporal. Cámbiala antes de entrar a tu espacio de trabajo."
      compact
    >
      <div className="auth-panel-heading">
        <span>Primer acceso</span>
        <h2>Cambiar clave temporal</h2>
        <p>Al guardar la nueva contraseña, inicia sesión otra vez con ella.</p>
      </div>

      <form onSubmit={onSubmit} className="auth-form" noValidate>
        <Input
          id="current_password"
          type="password"
          label="Clave temporal"
          autoComplete="current-password"
          error={errors.current_password?.message}
          {...register('current_password')}
        />
        <Input
          id="password"
          type="password"
          label="Contraseña nueva"
          autoComplete="new-password"
          error={errors.password?.message}
          {...register('password')}
        />
        <Input
          id="password_confirmation"
          type="password"
          label="Repite la contraseña nueva"
          autoComplete="new-password"
          error={errors.password_confirmation?.message}
          {...register('password_confirmation')}
        />

        {serverError && <div className="auth-error" role="alert">{serverError}</div>}

        <Button type="submit" disabled={isSubmitting} className="auth-submit">
          {isSubmitting ? 'Guardando…' : 'Guardar contraseña nueva'}
        </Button>
      </form>

      <Link className="auth-back-link" to="/olvide-contrasena">¿No tienes la clave temporal?</Link>
    </AuthShell>
  );
}
