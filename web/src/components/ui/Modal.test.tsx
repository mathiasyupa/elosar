// @vitest-environment jsdom
import { createRef } from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Modal } from './Modal';

describe('Modal', () => {
  afterEach(cleanup);

  it('moves focus inside and restores the trigger after closing', async () => {
    const onClose = vi.fn();
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    const { unmount } = render(
      <Modal title="Nuevo paciente" onClose={onClose}>
        <input aria-label="Nombre completo" />
      </Modal>,
    );

    expect(document.activeElement).toBe(screen.getByLabelText('Nombre completo'));
    unmount();
    expect(document.activeElement).toBe(trigger);
    trigger.remove();
  });

  it('does not close through Escape or overlay while pending', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <Modal title="Nuevo paciente" onClose={onClose} pending>
        <input aria-label="Nombre completo" />
      </Modal>,
    );

    await user.keyboard('{Escape}');
    await user.click(document.querySelector('.modal-overlay')!);
    expect(onClose).not.toHaveBeenCalled();
  });

  it('uses the requested initial focus target and traps tab navigation', async () => {
    const user = userEvent.setup();
    const initialFocusRef = createRef<HTMLButtonElement>();
    render(
      <Modal title="Nuevo paciente" onClose={vi.fn()} initialFocusRef={initialFocusRef}>
        <input aria-label="Nombre completo" />
        <button ref={initialFocusRef} type="button">
          Guardar
        </button>
      </Modal>,
    );

    const saveButton = screen.getByRole('button', { name: 'Guardar' });
    expect(document.activeElement).toBe(saveButton);
    await user.tab();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cerrar' }));
    await user.tab({ shift: true });
    expect(document.activeElement).toBe(saveButton);
  });

  it('wraps both directions when the dialog itself receives fallback focus', async () => {
    const user = userEvent.setup();
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    const { unmount } = render(
      <Modal
        title="Confirmar cambios"
        onClose={vi.fn()}
        footer={<button type="button">Guardar cambios</button>}
      >
        <p>Los cambios se guardarán al confirmar.</p>
      </Modal>,
    );

    const dialog = screen.getByRole('dialog');
    const closeButton = screen.getByRole('button', { name: 'Cerrar' });
    const saveButton = screen.getByRole('button', { name: 'Guardar cambios' });
    expect(document.activeElement).toBe(dialog);

    await user.tab({ shift: true });
    expect(document.activeElement).toBe(saveButton);
    dialog.focus();
    await user.tab();
    expect(document.activeElement).toBe(closeButton);

    unmount();
    trigger.remove();
  });

  it('keeps fallback focus when a pending modal has no focusable controls', async () => {
    const user = userEvent.setup();
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    const { unmount } = render(
      <Modal title="Procesando" onClose={vi.fn()} pending>
        <p>Estamos guardando los cambios.</p>
      </Modal>,
    );

    const dialog = screen.getByRole('dialog');
    expect(document.activeElement).toBe(dialog);
    await user.tab();
    expect(document.activeElement).toBe(dialog);
    await user.tab({ shift: true });
    expect(document.activeElement).toBe(dialog);

    unmount();
    trigger.remove();
  });

  it('wraps a single body control in both directions', async () => {
    const user = userEvent.setup();
    render(
      <Modal title="Nuevo paciente" onClose={vi.fn()}>
        <input aria-label="Nombre completo" />
      </Modal>,
    );

    const closeButton = screen.getByRole('button', { name: 'Cerrar' });
    const nameInput = screen.getByLabelText('Nombre completo');
    closeButton.focus();
    await user.tab({ shift: true });
    expect(document.activeElement).toBe(nameInput);
    await user.tab();
    expect(document.activeElement).toBe(closeButton);
  });

  it('labels the dialog with its title and optional description', () => {
    render(
      <Modal
        title="Nuevo paciente"
        description="Registra los datos principales antes de agendar la consulta."
        onClose={vi.fn()}
      >
        <input aria-label="Nombre completo" />
      </Modal>,
    );

    const dialog = screen.getByRole('dialog');
    const titleId = dialog.getAttribute('aria-labelledby');
    const descriptionId = dialog.getAttribute('aria-describedby');

    expect(titleId).toBeTruthy();
    expect(descriptionId).toBeTruthy();
    expect(document.getElementById(titleId!)?.textContent).toBe('Nuevo paciente');
    expect(document.getElementById(descriptionId!)?.textContent).toBe(
      'Registra los datos principales antes de agendar la consulta.',
    );
  });

  // "Nuevo paciente" opens on top of "Nueva consulta" (and of the agenda's
  // appointment dialog). Both listened to the keyboard: Tab was pulled back by
  // the dialog underneath, and Escape closed both and lost what was typed.
  it('lets only the topmost of two stacked dialogs answer the keyboard', async () => {
    const user = userEvent.setup();
    const closeOuter = vi.fn();
    const closeInner = vi.fn();
    render(
      <>
        <Modal title="Nueva consulta" onClose={closeOuter}>
          <input aria-label="Buscar paciente" />
        </Modal>
        <Modal title="Nuevo paciente" onClose={closeInner}>
          <input aria-label="Nombre completo" />
          <input aria-label="Teléfono" />
        </Modal>
      </>,
    );

    await user.click(screen.getByLabelText('Nombre completo'));
    await user.keyboard('{Tab}');
    expect(document.activeElement).toBe(screen.getByLabelText('Teléfono'));

    await user.keyboard('{Escape}');
    expect(closeInner).toHaveBeenCalledOnce();
    expect(closeOuter).not.toHaveBeenCalled();
  });
});
