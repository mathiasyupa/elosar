import { useQuery } from '@tanstack/react-query';
import { fetchSettings } from '@/api/settings';
import { useWorkspaceCapabilities } from '@/lib/capabilities';

/**
 * Whether a document would come out on something other than a blank page.
 *
 * The rule is NOT re-derived here: `has_letterhead` comes straight from
 * Workspace::hasLetterhead(), the same method the onboarding checklist reads.
 * It has to account for the designed sheet as well as the system letterhead —
 * a clinic that prints on its own paper has a letterhead even with every
 * header field empty — and duplicating that on the client would drift.
 *
 * Shares the ['settings'] query key with Ajustes, so opening a patient tab costs
 * no extra request once the settings have been read (and vice versa).
 */
export function useLetterheadStatus(): { configured: boolean; known: boolean } {
  // GET /settings needs settings.view (nursing, front desk and the cash desk
  // don't have it). Without the data we cannot tell, and warning someone who
  // can neither check nor fix it is just noise.
  const { can } = useWorkspaceCapabilities();
  const enabled = can('settings.view');

  const { data } = useQuery({ queryKey: ['settings'], queryFn: fetchSettings, enabled });

  if (!enabled || !data) {
    return { configured: true, known: false };
  }

  return { configured: data.has_letterhead, known: true };
}
