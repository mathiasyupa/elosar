import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { resendVerification } from '@/api/auth';
import { Button } from '@/components/ui/Button';
import { AuthShell } from './components/AuthShell';

/**
 * Where a self-registration lands. The account exists but has no session yet:
 * the address has to answer first, because a reset link is only as good as the
 * inbox it reaches.
 */
export function VerifyEmailNoticePage() {
  const [params] = useSearchParams();
  const email = params.get('email') ?? '';

  const [resent, setResent] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const resend = async () => {
    setError(null);
    setSending(true);
    try {
      await resendVerification(email);
      setResent(true);
    } catch {
      setError('No pudimos reenviarlo ahora. Espera un momento y vuelve a intentarlo.');
    } finally {
      setSending(false);
    }
  };

  return (
    <AuthShell
      current="register"
      eyebrow="Acceso a Elosar"
      title="Confirma tu correo."
      description="Es el último paso. Lo pedimos porque de ese correo depende que puedas recuperar tu cuenta si algún día olvidas la contraseña."
      compact
    >
      <div className="auth-panel-heading">
        <span>Cuenta creada</span>
        <h2>Revisa tu correo</h2>
        <p>
          {email
            ? `Enviamos un enlace de confirmación a ${email}.`
            : 'Enviamos un enlace de confirmación a tu correo.'}
        </p>
      </div>

      <div className="auth-success" role="status">
        <strong>Tu cuenta ya está creada</strong>
        <p>
          Abre el enlace y podrás entrar a Elosar. Si no lo ves en unos minutos, revisa la carpeta de spam
          antes de pedir otro.
        </p>
      </div>

      {resent && (
        <div className="auth-success" role="status">
          <strong>Enlace reenviado</strong>
          <p>Si la cuenta existe y aún no está confirmada, te llegará uno nuevo en un momento.</p>
        </div>
      )}

      {error && (
        <div className="auth-error" role="alert">
          <strong>No fue posible reenviarlo</strong>
          <p>{error}</p>
        </div>
      )}

      {email && !resent && (
        <Button type="button" className="auth-submit" onClick={() => void resend()} disabled={sending}>
          {sending ? 'Reenviando…' : 'Reenviar el enlace'}
        </Button>
      )}

      <div className="auth-secondary">
        <span>¿Ya lo confirmaste?</span>
        <Link to="/login">Iniciar sesión</Link>
      </div>
      <Link className="auth-back-link" to="/">
        Volver al producto
      </Link>
    </AuthShell>
  );
}
