import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { Icon } from './Icon';
import { captureError } from '@/lib/monitoring';

interface ErrorBoundaryProps {
  children: ReactNode;
  /**
   * `page` fills the viewport — used at the root, where the shell itself may be
   * gone. `section` renders an inline card so the sidebar and topbar survive.
   * `silent` renders nothing: for auxiliary widgets (assistant, switcher) where
   * disappearing is better than dragging the shell down with them.
   */
  variant?: 'page' | 'section' | 'silent';
  /** Names the failing area in the message ("No pudimos mostrar la agenda"). */
  area?: string;
  /** Sent to monitoring so a report says which boundary caught the crash. */
  boundary: string;
  /** Changing this value clears the error — used to reset on route changes. */
  resetKey?: string | number;
}

interface ErrorBoundaryState {
  error: Error | null;
}

/**
 * Catches render/lifecycle crashes so a bug in one screen never leaves the
 * doctor staring at a white page. React has no hook equivalent — an error
 * boundary must be a class component.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    captureError(error, {
      boundary: this.props.boundary,
      area: this.props.area,
      componentStack: info.componentStack,
    });
  }

  componentDidUpdate(prev: ErrorBoundaryProps): void {
    // Navigating away from a broken screen should give the app a clean slate.
    if (this.state.error && prev.resetKey !== this.props.resetKey) {
      this.setState({ error: null });
    }
  }

  private reset = (): void => this.setState({ error: null });

  private reload = (): void => window.location.reload();

  render(): ReactNode {
    const { error } = this.state;
    if (!error) return this.props.children;

    const { variant = 'section', area } = this.props;
    if (variant === 'silent') return null;

    const heading = area ? `No pudimos mostrar ${area}` : 'Algo salió mal';

    return (
      <div className={variant === 'page' ? 'crash crash-page' : 'crash crash-section'} role="alert">
        <div className="crash-icon" aria-hidden="true">
          <Icon name="alert" size={variant === 'page' ? 34 : 26} />
        </div>

        <h2 className="crash-title">{heading}</h2>
        <p className="crash-text">
          {variant === 'page'
            ? 'La aplicación encontró un problema inesperado. Tus datos están a salvo — vuelve a cargar la página para continuar.'
            : 'Esta sección encontró un problema inesperado. El resto de la aplicación sigue funcionando: puedes reintentar o moverte a otra pantalla desde el menú.'}
        </p>

        <div className="crash-actions">
          {variant === 'section' && (
            <button type="button" className="btn" onClick={this.reset}>
              <Icon name="refresh" size={16} />
              Reintentar
            </button>
          )}
          <button type="button" className="btn primary" onClick={this.reload}>
            <Icon name="refresh" size={16} />
            Recargar la página
          </button>
        </div>

        {/* The message is only useful to a developer, and could echo back data
            from the failing screen — keep it out of production builds. */}
        {import.meta.env.DEV && (
          <pre className="crash-detail">
            {error.name}: {error.message}
          </pre>
        )}
      </div>
    );
  }
}
