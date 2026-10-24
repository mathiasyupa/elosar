import { Icon } from './Icon';

interface Props {
  /** Patient email; the button is disabled when missing. */
  email: string | null | undefined;
  /** Fires the server-side send (a mutation). */
  onSend: () => void;
  pending?: boolean;
  label?: string;
  className?: string;
}

/**
 * Triggers a server-sent email (the system sends it, Reply-To the doctor) —
 * unlike WhatsApp which opens a chat. Disabled with a hint when the patient has
 * no email on file.
 */
export function EmailButton({ email, onSend, pending = false, label = 'Email', className = '' }: Props) {
  const disabled = !email || pending;
  return (
    <button
      className={`btn sm email ${className}`.trim()}
      disabled={disabled}
      onClick={onSend}
      type="button"
      title={!email ? 'Sin correo registrado' : 'Enviar por correo (lo envía el sistema)'}
    >
      <Icon name="mail" size={15} /> {pending ? 'Enviando…' : label}
    </button>
  );
}
