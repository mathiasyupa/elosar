import { useEffect, useId, useRef, type ReactNode, type RefObject } from 'react';
import { Icon } from '@/components/Icon';

interface ModalProps {
  title: string;
  description?: string;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'md' | 'lg';
  pending?: boolean;
  initialFocusRef?: RefObject<HTMLElement>;
}

const focusableSelector = [
  'button:not([disabled])',
  '[href]',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function getFocusableElements(container: HTMLElement) {
  return Array.from(container.querySelectorAll<HTMLElement>(focusableSelector)).filter(
    (element) => !element.hasAttribute('disabled') && element.tabIndex >= 0,
  );
}

/** Centered modal dialog matching the design tokens. Closes on overlay click + Esc. */
/**
 * Dialogs open on top of each other ("Nuevo paciente" over "Nueva consulta",
 * over the agenda's appointment dialog). Each one listens on `document`, so
 * without this only-the-top rule the one underneath pulled Tab focus back to
 * itself and Escape closed both — the form looked frozen and lost its data.
 */
const openDialogs: symbol[] = [];

export function Modal({
  title,
  description,
  onClose,
  children,
  footer,
  size = 'md',
  pending = false,
  initialFocusRef,
}: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const titleId = useId();
  const descriptionId = useId();
  const stackId = useRef(Symbol('dialog')).current;

  useEffect(() => {
    openDialogs.push(stackId);
    return () => {
      const index = openDialogs.lastIndexOf(stackId);
      if (index >= 0) openDialogs.splice(index, 1);
    };
  }, [stackId]);

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const dialog = dialogRef.current;
    const body = dialog?.querySelector<HTMLElement>('.modal-body');
    const initialFocusTarget =
      initialFocusRef?.current ?? (body ? getFocusableElements(body)[0] : undefined) ?? dialog;

    initialFocusTarget?.focus();

    return () => {
      if (previouslyFocused?.isConnected) previouslyFocused.focus();
    };
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (openDialogs[openDialogs.length - 1] !== stackId) return;
      if (e.key === 'Escape') {
        if (!pending) onClose();
        return;
      }

      if (e.key !== 'Tab') return;

      const dialog = dialogRef.current;
      if (!dialog) return;

      const focusableElements = getFocusableElements(dialog);
      if (focusableElements.length === 0) {
        e.preventDefault();
        dialog.focus();
        return;
      }

      const firstElement = focusableElements[0];
      const lastElement = focusableElements[focusableElements.length - 1];
      const activeElement = document.activeElement;
      const isDialogFocusFallback = activeElement === dialog || !dialog.contains(activeElement);

      if (e.shiftKey && (activeElement === firstElement || isDialogFocusFallback)) {
        e.preventDefault();
        lastElement.focus();
      } else if (!e.shiftKey && (activeElement === lastElement || isDialogFocusFallback)) {
        e.preventDefault();
        firstElement.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose, pending, stackId]);

  const dismiss = () => {
    if (!pending) onClose();
  };

  return (
    <div className="modal-overlay" onClick={dismiss} role="presentation">
      <div
        ref={dialogRef}
        className={`modal ${size === 'lg' ? 'lg' : ''}`.trim()}
        onClick={(e) => e.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
      >
        <div className="modal-head">
          <div className="modal-title-block">
            <h3 id={titleId}>{title}</h3>
            {description && (
              <p className="modal-description" id={descriptionId}>
                {description}
              </p>
            )}
          </div>
          <button
            className="icon-btn"
            type="button"
            onClick={dismiss}
            aria-label="Cerrar"
            disabled={pending}
          >
            <Icon name="x" size={18} />
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}
