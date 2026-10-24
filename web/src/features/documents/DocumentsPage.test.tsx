// @vitest-environment jsdom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchDocuments, downloadDocumentByUrl, uploadPatientDocument, type DocumentItem } from '@/api/documents';
import { useAuthStore } from '@/store/auth';
import { DocumentsPage, kindOf } from './DocumentsPage';

vi.mock('@/api/documents', () => ({
  fetchDocuments: vi.fn(),
  uploadPatientDocument: vi.fn(),
  downloadDocumentByUrl: vi.fn(),
}));
vi.mock('@/api/records', () => ({ downloadAttachment: vi.fn() }));
// The picker searches every patient through its own query; here it only has to exist.
vi.mock('@/components/PatientPicker', () => ({
  PatientPicker: ({ onSelect }: { onSelect: (p: unknown) => void }) => (
    <button type="button" onClick={() => onSelect({ id: 9, name: 'Ana Torres' })}>Elegir paciente</button>
  ),
}));

const today = new Date().toISOString();

function doc(over: Partial<DocumentItem>): DocumentItem {
  return {
    id: 'x', numeric_id: 1, source: 'attachment', type: 'attachment', title: 'archivo.pdf', notes: null,
    record_id: null, record_date: today, download_url: '/api/v1/x', extension: 'pdf',
    patient: { id: 5, name: 'María Pérez', id_number: '1710034065' }, ...over,
  };
}

const DOCS = [
  doc({ id: 'rx-1', source: 'prescription', type: 'prescription', title: 'Receta médica: Amoxicilina' }),
  doc({ id: 'cert-1', source: 'certificate', type: 'certificate', title: 'Certificado médico', patient: { id: 6, name: 'Carlos Ruiz', id_number: '1700000008' } }),
  doc({ id: 'order-1', source: 'study_order', type: 'lab', title: 'Orden de Laboratorio: Biometría' }),
  doc({ id: 'att-1', title: 'hemograma.pdf', category: 'lab', type: 'lab' }),
  doc({ id: 'att-2', title: 'escaneo.pdf' }),
];

function renderPage() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <DocumentsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeAll(() => {
  URL.createObjectURL = vi.fn(() => 'blob:doc');
  URL.revokeObjectURL = vi.fn();
});

beforeEach(() => {
  useAuthStore.setState({
    workspaces: [{ id: 4, name: 'Consultorio', is_clinic: false, permissions: ['records.view', 'records.manage'], roles: [] } as never],
    activeWorkspaceId: 4,
  });
  vi.mocked(fetchDocuments).mockResolvedValue(DOCS);
  vi.mocked(downloadDocumentByUrl).mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }));
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ workspaces: [], activeWorkspaceId: null });
});

describe('kindOf', () => {
  it('files an unknown PDF as «Otro», not as a receta', () => {
    expect(kindOf(doc({ title: 'escaneo.pdf' }))).toBe('other');
    expect(kindOf(doc({ source: 'prescription' }))).toBe('prescription');
    expect(kindOf(doc({ source: 'study_order', type: 'image' }))).toBe('order');
    expect(kindOf(doc({ category: 'image' }))).toBe('image');
    expect(kindOf(doc({ title: 'rx-torax.jpg', extension: 'jpg' }))).toBe('image');
  });
});

describe('Documentos', () => {
  it('lists every document on one screen, grouped by date, with counts per type', async () => {
    renderPage();

    const list = await screen.findByRole('list', { name: 'Documentos' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(5);
    expect(within(list).getByText('Hoy')).toBeTruthy();
    expect(screen.getByRole('radio', { name: /Recetas 1/ })).toBeTruthy();
    expect(screen.getByRole('radio', { name: /Otros 1/ })).toBeTruthy();
  });

  it('searches across all documents by patient, and filters by type', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.type(await screen.findByRole('textbox', { name: 'Buscar documentos' }), 'carlos');
    let list = screen.getByRole('list', { name: 'Documentos' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(within(list).getByText('Certificado médico')).toBeTruthy();

    await user.clear(screen.getByRole('textbox', { name: 'Buscar documentos' }));
    await user.click(screen.getByRole('radio', { name: /Resultados/ }));
    list = screen.getByRole('list', { name: 'Documentos' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(within(list).getByText('hemograma.pdf')).toBeTruthy();
  });

  it('shows the selected document beside the list', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText('Certificado médico'));
    const aside = screen.getByRole('complementary', { name: 'Documento seleccionado' });
    expect(await within(aside).findByTitle('Vista previa: Certificado médico')).toBeTruthy();
    expect(within(aside).getByRole('link', { name: /Carlos Ruiz/ }).getAttribute('href')).toBe('/pacientes/6/documentos');
  });

  it('uploads with the type chosen by hand', async () => {
    const user = userEvent.setup();
    vi.mocked(uploadPatientDocument).mockResolvedValue(doc({}));
    renderPage();

    await user.click(await screen.findByRole('button', { name: /Subir documento/ }));
    const dialog = screen.getByRole('dialog', { name: 'Subir documento a paciente' });
    await user.click(within(dialog).getByRole('button', { name: 'Elegir paciente' }));
    await user.upload(within(dialog).getByLabelText('Archivo'), new File(['x'], 'rx.jpg', { type: 'image/jpeg' }));
    await user.selectOptions(within(dialog).getByLabelText('Tipo'), 'image');
    await user.click(within(dialog).getByRole('button', { name: 'Subir documento' }));

    await waitFor(() =>
      expect(uploadPatientDocument).toHaveBeenCalledWith(9, expect.any(File), '', '', 'image'),
    );
  });
});
