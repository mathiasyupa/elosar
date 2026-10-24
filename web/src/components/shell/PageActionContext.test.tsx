// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useMemo, useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
  PageActionProvider,
  usePageAction,
  usePageActionRegistration,
  type PageAction,
} from './PageActionContext';

function Registrar({ action }: { action: PageAction | null }) {
  usePageActionRegistration(action);
  return null;
}

function Consumer() {
  const actions = usePageAction();
  return <span>{actions.length ? actions.map((a) => a.label).join(', ') : 'Sin acción'}</span>;
}

function Harness() {
  const [visible, setVisible] = useState(true);
  const action = useMemo(
    () => ({ id: 'patient.new', label: 'Nuevo paciente', icon: 'plus', onInvoke: vi.fn() }),
    [],
  );
  return (
    <PageActionProvider>
      {visible && <Registrar action={action} />}
      <Consumer />
      <button onClick={() => setVisible(false)}>Ocultar</button>
    </PageActionProvider>
  );
}

describe('PageActionContext', () => {
  it('registers an explicit action and clears it when the owner unmounts', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    expect(screen.getByText('Nuevo paciente')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Ocultar' }));
    expect(screen.getByText('Sin acción')).toBeTruthy();
  });

  it('requires consumers to live inside the provider', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    try {
      expect(() => render(<Consumer />)).toThrow(/PageActionProvider/);
    } finally {
      consoleError.mockRestore();
    }
  });

  it('preserves a replacement action when the previous owner unmounts late', async () => {
    const user = userEvent.setup();
    render(<ReplacementHarness />);

    expect(screen.getByText('Acción A')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Registrar B' }));
    expect(screen.getByText('Acción A, Acción B')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Desmontar A' }));
    expect(screen.getByText('Acción B')).toBeTruthy();
  });

  it('registers multiple actions at the same time', () => {
    const actionA: PageAction = { id: 'action.a', label: 'Acción A', icon: 'a', onInvoke: vi.fn() };
    const actionB: PageAction = { id: 'action.b', label: 'Acción B', icon: 'b', onInvoke: vi.fn() };

    render(
      <PageActionProvider>
        <Registrar action={actionA} />
        <Registrar action={actionB} />
        <Consumer />
      </PageActionProvider>,
    );

    expect(screen.getByText('Acción A, Acción B')).toBeTruthy();
  });
});

function ReplacementHarness() {
  const [showA, setShowA] = useState(true);
  const [showB, setShowB] = useState(false);
  const actionA = useMemo(
    () => ({ id: 'action.a', label: 'Acción A', icon: 'a', onInvoke: vi.fn() }),
    [],
  );
  const actionB = useMemo(
    () => ({ id: 'action.b', label: 'Acción B', icon: 'b', onInvoke: vi.fn() }),
    [],
  );

  return (
    <PageActionProvider>
      {showA && <Registrar action={actionA} />}
      {showB && <Registrar action={actionB} />}
      <Consumer />
      <button onClick={() => setShowB(true)}>Registrar B</button>
      <button onClick={() => setShowA(false)}>Desmontar A</button>
    </PageActionProvider>
  );
}
