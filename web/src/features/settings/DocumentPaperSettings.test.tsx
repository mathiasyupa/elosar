// @vitest-environment jsdom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { useUiStore } from '@/store/ui';
import type { WorkspaceSettings } from '@/api/types';
import { DocumentPaperSettings, paperTiming } from './DocumentPaperSettings';

vi.mock('@/api/settings', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/settings')>()),
  previewDocumentPdf: vi.fn(),
  updateLetterhead: vi.fn(),
  uploadLogo: vi.fn(),
  deleteLogo: vi.fn(),
  uploadHeaderImage: vi.fn(),
  deleteHeaderImage: vi.fn(),
  uploadSheetImage: vi.fn(),
  deleteSheetImage: vi.fn(),
}));

const { previewDocumentPdf, updateLetterhead } = await import('@/api/settings');

function settings(over: Partial<WorkspaceSettings> = {}, prescription: Record<string, unknown> = {}): WorkspaceSettings {
  return {
    id: 1, name: 'Consultorio', type: 'personal', is_clinic: false, is_owner: true,
    logo_path: null, logo_url: null, has_letterhead: false,
    prescription_settings: { header: 'Dr. Demo', ...prescription },
    sheet_image_url: null, sheet_image_path: null,
    ...over,
  } as WorkspaceSettings;
}

function renderPaper(s: WorkspaceSettings) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <DocumentPaperSettings settings={s} />
    </QueryClientProvider>,
  );
}

const option = (group: string, name: string) =>
  within(screen.getByRole('radiogroup', { name: group })).getByRole('radio', { name });
const checked = (group: string, name: string) => option(group, name).getAttribute('aria-checked') === 'true';
const letterheadCard = () => screen.queryByText('Membrete de Elosar', { selector: 'h3' });

beforeAll(() => {
  // jsdom has no object URLs; the preview only needs one to exist.
  URL.createObjectURL = vi.fn(() => 'blob:preview');
  URL.revokeObjectURL = vi.fn();
});

beforeEach(() => {
  paperTiming.previewDelayMs = 10;
  vi.mocked(previewDocumentPdf).mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }));
  vi.mocked(updateLetterhead).mockResolvedValue(settings());
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useUiStore.setState({ toasts: [] });
});

describe('Papelería de documentos', () => {
  it('never leaves an old preview passing for the current one when a redraw fails', async () => {
    vi.mocked(previewDocumentPdf)
      .mockResolvedValueOnce(new Blob(['%PDF'], { type: 'application/pdf' }))
      .mockRejectedValue(new Error('500'));
    renderPaper(settings({}, { header_mode: 'image' }));

    await screen.findByTitle(/Vista previa/);
    await userEvent.click(option('Estilo', 'Con mis datos'));

    expect((await screen.findByRole('alert')).textContent).toMatch(/No se pudo dibujar la vista previa/);
    expect(screen.queryByTitle(/Vista previa/)).toBeNull();
    expect(screen.getByText('No se actualizó')).toBeTruthy();

    vi.mocked(previewDocumentPdf).mockResolvedValue(new Blob(['%PDF'], { type: 'application/pdf' }));
    await userEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
    await screen.findByTitle(/Vista previa/);
  });

  it('starts with no sheet: everything on the Elosar letterhead, receta in two halves', () => {
    renderPaper(settings());

    expect(checked('Cómo usas tu hoja', 'No tengo hoja')).toBe(true);
    expect(checked('Formato', 'Horizontal · dos mitades')).toBe(true);
    expect(letterheadCard()).toBeTruthy();
    // Without a sheet there is nothing to choose for the receta's paper.
    expect(screen.queryByRole('radiogroup', { name: 'Papel de la receta' })).toBeNull();
    expect(screen.queryByText(/Margen arriba/)).toBeNull();
  });

  it('keeps a sheet set up before this on every document', () => {
    renderPaper(settings({ sheet_image_url: 'https://x/hoja.png' }, { sheet_mode: 'background' }));

    expect(checked('Cómo usas tu hoja', 'Sale de fondo')).toBe(true);
    expect(checked('Papel de la receta', 'En mi hoja')).toBe(true);
    // The sheet carries the clinic's identity: no letterhead fields unless it is only a frame.
    expect(letterheadCard()).toBeNull();
  });

  it('puts the certificate and the order on the sheet, and lets only the receta take the letterhead', async () => {
    const user = userEvent.setup();
    renderPaper(settings({ sheet_image_url: 'https://x/hoja.png' }));

    await user.click(option('Cómo usas tu hoja', 'Sale de fondo'));
    await user.click(option('Papel de la receta', 'Con el membrete de Elosar'));
    expect(letterheadCard()).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));

    await waitFor(() =>
      expect(updateLetterhead).toHaveBeenCalledWith(expect.objectContaining({
        sheet_mode: 'background',
        paper: { prescription: 'letterhead', certificate: 'sheet', order: 'sheet' },
      })),
    );
  });

  it('chooses the receta format and shows it in the preview at once, unsaved', async () => {
    const user = userEvent.setup();
    renderPaper(settings());

    await waitFor(() => expect(previewDocumentPdf).toHaveBeenCalledWith('prescription', expect.anything()));
    await user.click(screen.getByRole('tab', { name: 'Certificado' }));
    await user.click(option('Formato', 'Vertical · una hoja'));

    expect(screen.getByRole('tab', { name: 'Receta' }).getAttribute('aria-selected')).toBe('true');
    await waitFor(() =>
      expect(vi.mocked(previewDocumentPdf).mock.calls.at(-1)).toEqual([
        'prescription',
        expect.objectContaining({ prescription_layout: 'portrait' }),
      ]),
    );
    expect(updateLetterhead).not.toHaveBeenCalled();
    expect(screen.getByText('Tienes cambios sin guardar')).toBeTruthy();
  });

  it('asks for the sheet when it should print but none was uploaded', async () => {
    const user = userEvent.setup();
    renderPaper(settings());

    await user.click(option('Cómo usas tu hoja', 'Sale de fondo'));
    expect(screen.getByText(/Sube tu hoja: mientras no haya imagen/)).toBeTruthy();
    expect(screen.getByText(/Margen arriba/)).toBeTruthy();
  });

  it('prints on the physical paper: margins only, the sheet image is optional', async () => {
    const user = userEvent.setup();
    renderPaper(settings());

    await user.click(option('Cómo usas tu hoja', 'Imprimo en mi papel'));
    expect(screen.queryByText(/Sube tu hoja: mientras no haya imagen/)).toBeNull();
    expect(checked('Papel de la receta', 'En mi papel')).toBe(true);
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));
    await waitFor(() =>
      expect(updateLetterhead).toHaveBeenCalledWith(expect.objectContaining({
        paper: { prescription: 'preprinted', certificate: 'preprinted', order: 'preprinted' },
      })),
    );
    expect(useUiStore.getState().toasts.map((t) => t.message)).toContain('Papelería guardada');
  });

  it('brings the letterhead fields back when the sheet is only a frame', async () => {
    const user = userEvent.setup();
    renderPaper(settings({ sheet_image_url: 'https://x/hoja.png' }, { sheet_mode: 'background' }));

    await user.click(screen.getByRole('checkbox', { name: /Mi hoja es solo un marco/ }));
    expect(letterheadCard()).toBeTruthy();
  });

  it('lets a member who is not the owner look, but not change anything', async () => {
    renderPaper(settings({ is_owner: false }));

    expect((option('Formato', 'Vertical · una hoja') as HTMLButtonElement).disabled).toBe(true);
    expect(screen.queryByRole('button', { name: 'Guardar cambios' })).toBeNull();
    expect(screen.getByText(/Puedes ver cómo salen tus documentos/)).toBeTruthy();
    await waitFor(() => expect(previewDocumentPdf).toHaveBeenCalled());
  });
});
