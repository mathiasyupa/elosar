import type { ReactNode } from 'react';
import { PublicNav } from '@/components/PublicNav';

interface AuthShellProps {
  current?: 'login' | 'register';
  eyebrow: string;
  title: string;
  description: string;
  children: ReactNode;
  contextSupplement?: ReactNode;
  compact?: boolean;
}

export function AuthShell({
  current,
  eyebrow,
  title,
  description,
  children,
  contextSupplement,
  compact = false,
}: AuthShellProps) {
  return (
    <div className="public-shell auth-shell">
      <PublicNav current={current} />
      <main className={`auth-layout${compact ? ' is-compact' : ''}`}>
        <aside className="auth-context">
          <p className="landing-eyebrow">{eyebrow}</p>
          <h1>{title}</h1>
          <p>{description}</p>
          <div className="auth-context-proof" aria-label="Principios de acceso">
            <span>Espacio de trabajo separado</span>
            <span>Permisos según responsabilidad</span>
            <span>Interfaz creada para Ecuador</span>
          </div>
        </aside>
        {contextSupplement && <div className="auth-context-supplement">{contextSupplement}</div>}
        <section className="auth-panel">{children}</section>
      </main>
    </div>
  );
}
