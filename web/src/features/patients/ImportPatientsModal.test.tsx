// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { importPatients } from '@/api/patients';
import { ImportPatientsModal } from './ImportPatientsModal';

vi.mock('@/api/patients', () => ({ importPatients: vi.fn() }));

function renderModal() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
      <ImportPatientsModal onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}

function legacyFile() {
  return new File([
    '<table><tr><th>Historia</th><th>ID</th><th>Apellidos</th><th>Nombres</th><th>Nacimiento</th><th>Edad</th><th>Teléfonos</th><th>Celular</th><th>E-mail</th><th>Ciudad</th></tr><tr><td>1</td><td>1712345678</td><td>Pérez</td><td>María</td><td>1990-05-15</td><td>36</td><td></td><td>0991234567</td><td>maria@example.com</td><td>Ibarra</td></tr></table>',
  ], 'pacientes.xls', { type: 'application/vnd.ms-excel' });
}

async function elosarExportFile() {
  const { default: ExcelJS } = await import('exceljs');
  const workbook = new ExcelJS.Workbook();
  const sheet = workbook.addWorksheet('Pacientes');
  sheet.addRow([
    'Nombre', 'Documento', 'Tipo', 'País', 'Fecha de nacimiento', 'Edad',
    'Género', 'Teléfono', 'Email', 'Dirección', 'Última visita',
  ]);
  sheet.addRow([
    'Paciente Exportada', '1729257186', 'Cédula', 'EC', new Date(1990, 6, 28), 36,
    'Otro', '0987306092', 'exportada@example.com', 'Av. Cristóbal de Troya 234', null,
  ]);

  return new File([await workbook.xlsx.writeBuffer() as ArrayBuffer], 'pacientes.xlsx', {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  });
}

afterEach(() => { cleanup(); vi.clearAllMocks(); });

describe('ImportPatientsModal', () => {
  it('requires a clean preview before it enables the full batch confirmation', async () => {
    const user = userEvent.setup();
    vi.mocked(importPatients).mockResolvedValue({ committed: false, ready: true, created: 0, errors: 0, rows: [] });
    renderModal();

    fireEvent.change(screen.getByLabelText(/Selecciona una exportación/i), { target: { files: [legacyFile()] } });

    await waitFor(() => expect(screen.getByRole('button', { name: /Validar 1 pacientes/i })).toBeTruthy());
    expect(screen.queryByText('Ibarra')).toBeNull();
    expect((screen.getByRole('button', { name: /Validar 1 pacientes/i }) as HTMLButtonElement).disabled).toBe(true);

    await user.selectOptions(screen.getByRole('combobox', { name: /Tipo de documento, fila 2/i }), 'foreign_id');

    expect((screen.getByRole('button', { name: /Validar 1 pacientes/i }) as HTMLButtonElement).disabled).toBe(false);
    await user.click(screen.getByRole('button', { name: /Validar 1 pacientes/i }));
    await waitFor(() => expect(importPatients).toHaveBeenCalledWith([
      expect.objectContaining({ address: null }),
    ], true));
    expect((screen.getByRole('button', { name: /Confirmar 1 pacientes/i }) as HTMLButtonElement).disabled).toBe(false);
  });

  it('accepts an XLSX exported by Elosar before sending it to the API', async () => {
    renderModal();

    fireEvent.change(screen.getByLabelText(/Selecciona una exportación/i), {
      target: { files: [await elosarExportFile()] },
    });

    await waitFor(() => expect(screen.getByText('Paciente Exportada')).toBeTruthy());
    expect(screen.getByText('Av. Cristóbal de Troya 234')).toBeTruthy();
    expect(screen.getByText('1729257186')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Validar 1 pacientes/i })).toBeTruthy();
  });

});
