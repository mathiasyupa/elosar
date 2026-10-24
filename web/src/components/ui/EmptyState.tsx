import type { ReactNode } from 'react';
import { Icon } from '@/components/Icon';

export function EmptyState({
  icon = 'folder',
  message,
  hint,
  action,
}: {
  icon?: string;
  message: string;
  /** Secondary explanatory line under the message. */
  hint?: string;
  /** Optional CTA (button/link) rendered under the text. */
  action?: ReactNode;
}) {
  return (
    <div className="empty">
      <Icon name={icon} size={40} />
      <p>{message}</p>
      {hint && <p className="empty-hint">{hint}</p>}
      {action && <div className="empty-action">{action}</div>}
    </div>
  );
}
