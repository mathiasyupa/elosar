import { whatsappLink } from '@/lib/whatsapp';
import { Icon } from './Icon';

interface Props {
  phone: string | null | undefined;
  message: string;
  label?: string;
  /** Extra classes appended to the base `btn sm` styling. */
  className?: string;
  /** Fired when the user opens the chat (e.g. to mark a reminder as sent). */
  onSent?: () => void;
}

/**
 * Opens WhatsApp (web/app) with a pre-written message via a wa.me deep link.
 * Disabled with a hint when the phone can't be normalized to a valid number.
 */
export function WhatsAppButton({ phone, message, label = 'WhatsApp', className = '', onSent }: Props) {
  const link = whatsappLink(phone, message);

  if (!link) {
    return (
      <button className={`btn sm ${className}`.trim()} disabled title="Sin teléfono válido para WhatsApp" type="button">
        <Icon name="whatsapp" size={15} /> {label}
      </button>
    );
  }

  return (
    <a
      className={`btn sm wa ${className}`.trim()}
      href={link}
      target="_blank"
      rel="noopener noreferrer"
      onClick={onSent}
    >
      <Icon name="whatsapp" size={15} /> {label}
    </a>
  );
}
