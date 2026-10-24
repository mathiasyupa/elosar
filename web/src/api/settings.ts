import { api } from './client';
import type { PrescriptionSettings, SriDiagnosticResult, WorkspaceSettings } from './types';

// Extend the shared types with the letterhead-banner fields here (via module
// augmentation) instead of editing types.ts, which currently carries unrelated
// in-flight changes we don't want to ship with this feature.
/** Margins (mm) that keep the content inside the designed sheet. */
export interface SheetMargins {
  top: number;
  right: number;
  bottom: number;
  left: number;
}

/**
 * How the clinic's designed sheet is used:
 *  - off        → no sheet.
 *  - background → printed behind the content (the PDF you send by WhatsApp).
 *  - margins    → not printed; only its margins apply, for clinics that print
 *                 on their physical pre-designed paper.
 */
export type SheetMode = 'off' | 'background' | 'margins';

/** The patient-facing documents that each choose their paper. */
export type DocumentKind = 'prescription' | 'certificate' | 'order';

/**
 * The paper a document goes out on:
 *  - letterhead → the system letterhead (logo and data);
 *  - sheet      → the designed sheet printed behind the content;
 *  - preprinted → the clinic's physical paper: margins only, nothing drawn.
 */
export type Paper = 'letterhead' | 'sheet' | 'preprinted';

export type PaperByDocument = Record<DocumentKind, Paper>;

export const DEFAULT_SHEET_MARGINS: SheetMargins = { top: 12, right: 14, bottom: 14, left: 14 };

declare module './types' {
  interface PrescriptionSettings {
    header_mode?: 'digital' | 'image' | null;
    /** Tallest the uploaded banner may print, in mm (null = each document's default). */
    header_image_height?: number | null;
    sheet_mode?: SheetMode | null;
    sheet_keeps_header?: boolean | null;
    sheet_margins?: SheetMargins | null;
    paper?: Partial<PaperByDocument> | null;
    /** Receta: two halves to cut (landscape) or one uncut A4 (portrait). */
    prescription_layout?: 'landscape' | 'portrait' | null;
    issue_city?: string | null;
  }
  interface WorkspaceSettings {
    header_image_path?: string | null;
    header_image_url?: string | null;
    sheet_image_path?: string | null;
    sheet_image_url?: string | null;
  }
}

export async function fetchSettings(): Promise<WorkspaceSettings> {
  const { data } = await api.get<{ data: WorkspaceSettings }>('/settings');
  return data.data;
}

export type SettingsUpdate = Partial<
  Pick<
    WorkspaceSettings,
    | 'name'
    | 'ruc'
    | 'razon_social'
    | 'nombre_comercial'
    | 'dir_matriz'
    | 'dir_establecimiento'
    | 'cod_establecimiento'
    | 'pto_emision'
    | 'contribuyente_especial'
    | 'obligado_contabilidad'
    | 'acess_establishment_code'
    | 'senescyt_note'
    | 'sri_environment'
    | 'sri_enabled'
  >
>;

export async function updateSettings(payload: SettingsUpdate): Promise<WorkspaceSettings> {
  const { data } = await api.put<{ data: WorkspaceSettings }>('/settings', payload);
  return data.data;
}

export async function updateLetterhead(settings: PrescriptionSettings): Promise<WorkspaceSettings> {
  const { data } = await api.put<{ data: WorkspaceSettings }>('/settings/letterhead', {
    prescription_settings: settings,
  });
  return data.data;
}

export async function uploadLogo(file: File): Promise<WorkspaceSettings> {
  const form = new FormData();
  form.append('logo', file);
  const { data } = await api.post<{ data: WorkspaceSettings }>('/settings/logo', form);
  return data.data;
}

export async function deleteLogo(): Promise<WorkspaceSettings> {
  const { data } = await api.delete<{ data: WorkspaceSettings }>('/settings/logo');
  return data.data;
}

export async function uploadHeaderImage(file: File): Promise<WorkspaceSettings> {
  const form = new FormData();
  form.append('header_image', file);
  const { data } = await api.post<{ data: WorkspaceSettings }>('/settings/header-image', form);
  return data.data;
}

export async function deleteHeaderImage(): Promise<WorkspaceSettings> {
  const { data } = await api.delete<{ data: WorkspaceSettings }>('/settings/header-image');
  return data.data;
}

export async function uploadSheetImage(file: File): Promise<WorkspaceSettings> {
  const form = new FormData();
  form.append('sheet_image', file);
  const { data } = await api.post<{ data: WorkspaceSettings }>('/settings/sheet-image', form);
  return data.data;
}

export async function deleteSheetImage(): Promise<WorkspaceSettings> {
  const { data } = await api.delete<{ data: WorkspaceSettings }>('/settings/sheet-image');
  return data.data;
}

/**
 * The real receta, certificate or study order, drawn with the settings being
 * edited (not saved), for the live preview beside the form. Watermarked
 * MUESTRA, over an obviously fictional patient.
 */
export async function previewDocumentPdf(document: DocumentKind, settings: PrescriptionSettings): Promise<Blob> {
  const { data } = await api.post(`/settings/letterhead/preview/${document}`, { prescription_settings: settings }, {
    responseType: 'blob',
  });
  return data as Blob;
}

/** Which paper each document uses: its own choice, else the old single switch. */
export function readPaper(settings: PrescriptionSettings | null | undefined): PaperByDocument {
  const legacy: Paper =
    settings?.sheet_mode === 'background' ? 'sheet' : settings?.sheet_mode === 'margins' ? 'preprinted' : 'letterhead';
  const chosen = settings?.paper ?? {};
  return {
    prescription: chosen.prescription ?? legacy,
    certificate: chosen.certificate ?? legacy,
    order: chosen.order ?? legacy,
  };
}

/**
 * Sample PDF showing how the current letterhead prints, over invented patient
 * data. Saves having to find a real patient and write a real prescription just
 * to check whether the logo came out right.
 */
export async function previewLetterheadPdf(): Promise<Blob> {
  const { data } = await api.get('/settings/letterhead/preview', { responseType: 'blob' });
  return data as Blob;
}

export async function uploadCertificate(file: File, password: string): Promise<WorkspaceSettings> {
  const form = new FormData();
  form.append('certificate', file);
  form.append('password', password);
  const { data } = await api.post<{ data: WorkspaceSettings }>('/settings/certificate', form);
  return data.data;
}

export function readPrescriptionSettings(data: WorkspaceSettings): PrescriptionSettings {
  return {
    header_mode: (data.prescription_settings?.header_mode as 'digital' | 'image' | undefined) ?? 'digital',
    header_image_height: (data.prescription_settings?.header_image_height as number | undefined) ?? null,
    header: (data.prescription_settings?.header as string | undefined) ?? '',
    subheader: (data.prescription_settings?.subheader as string | undefined) ?? '',
    address: (data.prescription_settings?.address as string | undefined) ?? '',
    phone: (data.prescription_settings?.phone as string | undefined) ?? '',
    email: (data.prescription_settings?.email as string | undefined) ?? '',
    footer: (data.prescription_settings?.footer as string | undefined) ?? '',
    validity_days: (data.prescription_settings?.validity_days as number | undefined) ?? 3,
    sheet_mode: (data.prescription_settings?.sheet_mode as SheetMode | undefined) ?? 'off',
    sheet_keeps_header: (data.prescription_settings?.sheet_keeps_header as boolean | undefined) ?? false,
    paper: readPaper(data.prescription_settings),
    prescription_layout: data.prescription_settings?.prescription_layout ?? 'landscape',
    sheet_margins: {
      ...DEFAULT_SHEET_MARGINS,
      ...((data.prescription_settings?.sheet_margins as Partial<SheetMargins> | undefined) ?? {}),
    },
  };
}

/**
 * Diagnoses the SRI setup. In the test environment this files a throwaway
 * voucher with the SRI, so it takes 7-15 s — the caller must show a spinner.
 */
export async function checkSriSetup(): Promise<SriDiagnosticResult> {
  const { data } = await api.post<{ data: SriDiagnosticResult }>('/settings/sri/check');
  return data.data;
}
