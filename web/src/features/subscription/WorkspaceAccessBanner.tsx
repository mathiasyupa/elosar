import { useState } from 'react';
import type { Workspace } from '@/api/types';
import { Icon } from '@/components/Icon';
import { commercialWhatsAppUrl } from '@/lib/whatsapp';
import { isWorkspaceWritable, trialDaysLeft, trialRemainingLabel } from '@/lib/workspaceAccess';

function wasTrialNoticeDismissed(key: string): boolean {
  try {
    return window.localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

/**
 * Tells the doctor where their space stands, above everything else.
 *
 * Two states are worth interrupting for: a trial running out, and a space that
 * has gone read-only. An active plan says nothing — a permanent banner about a
 * healthy account is noise.
 */
export function WorkspaceAccessBanner({ workspace }: { workspace: Workspace | null }) {
  const [dismissedKey, setDismissedKey] = useState<string | null>(null);
  if (!workspace?.entitlement) return null;

  const writable = isWorkspaceWritable(workspace);
  const remaining = trialRemainingLabel(workspace);
  const contactUrl = commercialWhatsAppUrl();
  const daysLeft = trialDaysLeft(workspace.entitlement);
  const trialPhase = daysLeft !== null && daysLeft <= 30 ? 'ending' : 'early';
  const trialKey = `elosar-trial-notice:${workspace.id}:${workspace.entitlement.trial_ends_at}:${trialPhase}`;

  if (writable && !remaining) return null;

  if (writable) {
    if (dismissedKey === trialKey || wasTrialNoticeDismissed(trialKey)) return null;

    const dismiss = () => {
      try {
        window.localStorage.setItem(trialKey, '1');
      } catch {
        // Still close the notice for this visit when browser storage is unavailable.
      }
      setDismissedKey(trialKey);
    };

    return (
      <div className="alert blue workspace-access-banner" role="status">
        <span className="ai" aria-hidden="true">
          ●
        </span>
        <div className="workspace-access-copy">
          <b>{remaining}</b>
          <p>
            Tienes todo disponible. Cuando termine, tu información y tus documentos siguen
            accesibles.
          </p>
          {contactUrl && (
            <div className="alert-cta">
              <a className="btn sm" href={contactUrl} target="_blank" rel="noreferrer">
                Activar mi cuenta
              </a>
            </div>
          )}
        </div>
        <button
          className="icon-btn workspace-access-dismiss"
          type="button"
          onClick={dismiss}
          aria-label="Cerrar aviso de prueba"
          title="Cerrar aviso"
        >
          <Icon name="x" size={18} />
        </button>
      </div>
    );
  }

  return (
    <div className="alert amber workspace-access-banner" role="status">
      <span className="ai" aria-hidden="true">
        ●
      </span>
      <div>
        <b>Este espacio está en modo de solo lectura</b>
        <p>
          Puedes consultar y descargar toda la información y los documentos que ya existen.
          Para volver a registrar atenciones, escríbenos y activamos tu cuenta.
        </p>
        {contactUrl && (
          <div className="alert-cta">
            <a className="btn sm" href={contactUrl} target="_blank" rel="noreferrer">
              Hablar por WhatsApp
            </a>
          </div>
        )}
      </div>
    </div>
  );
}
