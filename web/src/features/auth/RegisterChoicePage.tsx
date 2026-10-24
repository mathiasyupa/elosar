import { Link, Navigate, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '@/store/auth';
import { Icon } from '@/components/Icon';
import { AuthShell } from './components/AuthShell';
import { GoogleButton } from './components/GoogleButton';

const CHOICES = [
  {
    to: '/crear-cuenta/medico',
    icon: 'steth',
    index: '01',
    title: 'Soy médico',
    description:
      'Crea un espacio personal para atender, llevar el expediente clínico y emitir documentos.',
    note: 'Después podrás crear una clínica o unirte a una por invitación.',
  },
  {
    to: '/crear-cuenta/clinica',
    icon: 'building',
    index: '02',
    title: 'Administro una clínica',
    description:
      'Crea el espacio de la organización e invita a médicos, enfermería, recepción y caja.',
    note: 'Esta opción crea una cuenta administrativa; no crea un perfil médico.',
  },
] as const;

export function RegisterChoicePage() {
  const token = useAuthStore((state) => state.token);
  const [params] = useSearchParams();
  // Set when the browser arrives from the Google callback: the identity is
  // verified, the account type still isn't. Carried on to whichever wizard
  // they pick, which redeems it on submit.
  const googleCode = params.get('google');
  if (token) return <Navigate to="/dashboard" replace />;

  const linkTo = (to: string) => (googleCode ? `${to}?google=${encodeURIComponent(googleCode)}` : to);

  return (
    <AuthShell
      current="register"
      eyebrow="Crea tu cuenta"
      title="Elige el espacio que vas a crear."
      description="Crea tu espacio de trabajo en Elosar. Cada tipo de cuenta comienza con responsabilidades distintas."
    >
      <div className="auth-panel-heading">
        <span>Tipo de cuenta</span>
        <h2>¿Cómo vas a usar Elosar?</h2>
        <p>Elige una opción para continuar con los datos correspondientes.</p>
      </div>

      {googleCode ? (
        <div className="auth-google-locked" role="status">
          <strong>Cuenta de Google verificada</strong>
          <p>Solo falta elegir el tipo de espacio y completar tus datos profesionales.</p>
        </div>
      ) : (
        <>
          <GoogleButton label="Crear cuenta con Google" />
          <div className="auth-divider">o con tu correo</div>
        </>
      )}

      <div className="auth-choice-list">
        {CHOICES.map((choice) => (
          <Link to={linkTo(choice.to)} className="auth-choice" key={choice.to}>
            <span className="auth-choice-index">{choice.index}</span>
            <span className="auth-choice-icon">
              <Icon name={choice.icon} size={19} />
            </span>
            <span className="auth-choice-copy">
              <strong>{choice.title}</strong>
              <span>{choice.description}</span>
              <small>{choice.note}</small>
            </span>
            <Icon name="arrow" size={16} />
          </Link>
        ))}
      </div>

      <div className="auth-secondary">
        <span>¿Ya tienes una cuenta?</span>
        <Link to="/login">Iniciar sesión</Link>
      </div>
      <Link className="auth-back-link" to="/">
        Volver al producto
      </Link>
    </AuthShell>
  );
}
