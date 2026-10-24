// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PatientRowMenu } from './PatientRowMenu';

afterEach(cleanup);

describe('PatientRowMenu', () => {
  /**
   * The whole reason this exists. Deleting a patient was only reachable after
   * ticking a checkbox, so the toolbar with "Eliminar" appeared once you had
   * already decided to do the thing you could not know was possible.
   */
  it('offers deleting without having to select anything first', async () => {
    const user = userEvent.setup();
    const onDelete = vi.fn();
    render(<PatientRowMenu patientName="María López" onDelete={onDelete} />);

    await user.click(screen.getByRole('button', { name: /Más acciones para María López/i }));
    await user.click(screen.getByRole('menuitem', { name: /Eliminar/i }));

    expect(onDelete).toHaveBeenCalledTimes(1);
  });

  it('stays closed until asked', () => {
    render(<PatientRowMenu patientName="María López" onDelete={vi.fn()} />);

    expect(screen.queryByRole('menuitem', { name: /Eliminar/i })).toBeNull();
  });

  it('closes with Escape without deleting anything', async () => {
    const user = userEvent.setup();
    const onDelete = vi.fn();
    render(<PatientRowMenu patientName="María López" onDelete={onDelete} />);

    await user.click(screen.getByRole('button', { name: /Más acciones/i }));
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('menuitem', { name: /Eliminar/i })).toBeNull();
    expect(onDelete).not.toHaveBeenCalled();
  });

  /** A row menu inside a clickable row must not also open the row. */
  it('does not bubble its clicks up to the row', async () => {
    const user = userEvent.setup();
    const onRowClick = vi.fn();
    render(
      <tr onClick={onRowClick}>
        <td>
          <PatientRowMenu patientName="María López" onDelete={vi.fn()} />
        </td>
      </tr>,
      { container: document.body.appendChild(document.createElement('table')) },
    );

    await user.click(screen.getByRole('button', { name: /Más acciones/i }));

    expect(onRowClick).not.toHaveBeenCalled();
  });
});
