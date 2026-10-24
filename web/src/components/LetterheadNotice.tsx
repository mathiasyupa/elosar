import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useLetterheadStatus } from '@/lib/useLetterheadStatus';

/**
 * Warns, at the moment it matters, that this document will come out on a blank
 * page. Renders nothing when the letterhead is configured — whoever already did
 * the work is not nagged about it.
 *
 * Who sees what: the owner gets the warning plus the link. A doctor who is not
 * the owner gets the warning and who to ask, because Ajustes is not theirs to
 * edit. Nursing and the cash desk get nothing at all — they cannot read the
 * settings to begin with (see useLetterheadStatus), and they do not issue these
 * documents, so the warning would be noise they could not act on.
 */
export function LetterheadNotice({ className = '' }: { className?: string }) {
  const { configured } = useLetterheadStatus();
  const { can } = useWorkspaceCapabilities();

  if (configured) return null;

  return (
    <div className={`alert amber ${className}`.trim()}>
      <span className="ai"><Icon name="alert" size={16} /></span>
      <div>
        <b>Este documento saldrá sin membrete</b>
        <p>
          Configura el logo y los datos de tu consultorio para que aparezcan en las recetas, órdenes y
          certificados que entregas.
        </p>
        {can('settings.manage') ? (
          <Link className="btn sm alert-cta" to="/ajustes?pane=letterhead">
            Configurar membrete
          </Link>
        ) : (
          <p>Pídeselo al responsable del consultorio: se configura una sola vez, en Ajustes.</p>
        )}
      </div>
    </div>
  );
}
