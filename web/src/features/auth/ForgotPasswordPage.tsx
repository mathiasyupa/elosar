import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link } from 'react-router-dom';
import { forgotPassword } from '@/api/auth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { AuthShell } from './components/AuthShell';

const schema = z.object({
  email: z.string().email('Ingresa un correo válido'),
});

type FormValues = z.infer<typeof schema>;

export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    getValues,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = handleSubmit(async (values) => {
    setServerError(null);
    try {
      await forgotPassword(values.email);
      setSent(true);
    } catch {
      // Only a network or rate-limit failure lands here: the server answers 200
      // even for an address with no account, on purpose.
      setServerError('No pudimos procesar la solicitud. Vuelve a intentarlo en un momento.');
    }
  });

  return (
    <AuthShell
      current="login"
      eyebrow="Acceso a Elosar"
      title="Recupera tu acceso."
      description="Te enviamos un enlace para crear una contraseña nueva. Tu información y la de tus pacientes siguen intactas."
      compact
    >
      <div className="auth-panel-heading">
        <span>Recuperar contraseña</span>
        <h2>¿Olvidaste tu contraseña?</h2>
        <p>Escribe el correo de tu cuenta y te enviamos un enlace para restablecerla.</p>
      </div>

      {sent ? (
        <>
          {/* Worded so it confirms nothing about whether the address has an
              account — the server is deliberately silent on that, and the screen
              must not undo it. */}
          <div className="auth-success" role="status">
            <strong>Revisa tu correo</strong>
            <p>
              Si <b>{getValues('email')}</b> corresponde a una cuenta de Elosar, te enviamos un enlace para
              crear una contraseña nueva. Caduca en una hora y solo se puede usar una vez.
            </p>
          </div>
          <p className="auth-panel-footer">
            ¿No te llegó? Revisa la carpeta de spam, o <Link to="/olvide-contrasena">inténtalo de nuevo</Link>.
          </p>
        </>
      ) : (
        <form onSubmit={onSubmit} className="auth-form">
          <Input
            id="email"
            type="email"
            label="Correo electrónico"
            autoComplete="username"
            error={errors.email?.message}
            {...register('email')}
          />

          {serverError && (
            <div className="auth-error" role="alert">
              <strong>No fue posible enviar el enlace</strong>
              <p>{serverError}</p>
            </div>
          )}

          <Button type="submit" disabled={isSubmitting} className="auth-submit">
            {isSubmitting ? 'Enviando…' : 'Enviarme el enlace'}
          </Button>
        </form>
      )}

      <div className="auth-secondary">
        <span>¿Ya la recordaste?</span>
        <Link to="/login">Iniciar sesión</Link>
      </div>
      <Link className="auth-back-link" to="/">
        Volver al producto
      </Link>
    </AuthShell>
  );
}
