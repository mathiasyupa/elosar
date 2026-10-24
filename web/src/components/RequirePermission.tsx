import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { EmptyState } from './ui/EmptyState';
import type { Permission } from '@/api/types';

interface Props {
  /** Permission(s) required. With multiple, ANY of them grants access. */
  permission: Permission | Permission[];
  children: ReactNode;
  /** Optional custom fallback; defaults to a "sin acceso" card. */
  fallback?: ReactNode;
}

/**
 * Page-level guard: renders children only if the active workspace grants the
 * permission. UI convenience on top of the server-side EnsurePermission gate —
 * a direct API call without the permission still 403s.
 */
export function RequirePermission({ permission, children, fallback }: Props) {
  const { canAny } = useWorkspaceCapabilities();
  const required = Array.isArray(permission) ? permission : [permission];

  if (!canAny(required)) {
    return (
      <>
        {fallback ?? (
          <EmptyState
            icon="shield"
            message="No tienes acceso a esta sección con tu rol actual."
            hint="Si necesitas entrar, pídeselo al propietario de la clínica."
            action={
              <Link className="btn sm" to="/dashboard">
                Volver al inicio
              </Link>
            }
          />
        )}
      </>
    );
  }

  return <>{children}</>;
}
