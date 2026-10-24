import type { AppointmentImportRow, DocumentType, PatientImportRow } from '@/api/types';

export interface ImportParseIssue {
  line: number;
  message: string;
  kind: 'required_fields' | 'document';
}

export interface PatientImportParseResult {
  rows: PatientImportRow[];
  issues: ImportParseIssue[];
  format: 'legacy_html_xls' | 'elosar_xlsx';
}

/**
 * Best-effort parse of legacy .xls files that are actually HTML tables.
 * Also accepts real HTML pasted into a file.
 */
export async function parseHtmlTableFile(file: File): Promise<string[][]> {
  const bytes = await file.arrayBuffer();
  const text = decodeLegacyText(bytes);

  if (! /<table\b/i.test(text)) {
    throw new Error('Este archivo no contiene una tabla HTML compatible. Para este importador usa la exportación .xls original del sistema anterior.');
  }

  const parser = new DOMParser();
  const doc = parser.parseFromString(text, 'text/html');
  const table = doc.querySelector('table');

  if (!table) {
    throw new Error('No se encontró una tabla HTML en el archivo.');
  }

  return Array.from(table.querySelectorAll('tr')).map((row) =>
    Array.from(row.querySelectorAll('td, th')).map((cell) => cell.textContent?.trim() ?? ''),
  );
}

function decodeLegacyText(bytes: ArrayBuffer): string {
  const utf8 = new TextDecoder('utf-8').decode(bytes);

  // Legacy Orpheus exports are HTML named .xls and commonly use Windows-1252.
  // Only fall back when UTF-8 replacement characters show that decoding failed.
  return utf8.includes('\uFFFD') ? new TextDecoder('windows-1252').decode(bytes) : utf8;
}

/**
 * Map competitor column names to our canonical fields.
 */
function normalizeHeader(header: string): string {
  const h = header
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]/g, '');

  if (h.includes('apellido')) return 'last_name';
  if (h.includes('nombre') && !h.includes('apellido')) return 'first_name';
  if (h === 'id' || h === 'documento' || h === 'document' || h.includes('cedula')) return 'id_number';
  if (h.includes('nacimiento')) return 'birth_date';
  if (h.includes('edad')) return 'age';
  if (h.includes('telefono') || h.includes('telfono')) return 'phone';
  if (h.includes('celular') || h.includes('celular')) return 'mobile';
  if (h.includes('email') || h.includes('mail')) return 'email';
  if (h.includes('direccion')) return 'address';
  if (h.includes('ciudad')) return 'city';
  if (h.includes('estadocivil')) return 'civil_status';
  return h;
}

function isValidEcuadorianCedula(value: string): boolean {
  if (! /^\d{10}$/.test(value)) return false;

  const province = Number(value.slice(0, 2));
  const third = Number(value[2]);
  if (province < 1 || province > 24 || third > 6) return false;

  const total = value
    .slice(0, 9)
    .split('')
    .reduce((sum, digit, index) => {
      const product = Number(digit) * (index % 2 === 0 ? 2 : 1);
      return sum + (product >= 10 ? product - 9 : product);
    }, 0);

  return (10 - (total % 10)) % 10 === Number(value[9]);
}

function detectDocumentTypeAndCountry(idNumber: string): { type: DocumentType; country: string; warning: string | null } {
  const clean = idNumber.replace(/[^a-zA-Z0-9]/g, '');

  if (isValidEcuadorianCedula(clean)) {
    return { type: 'cedula', country: 'EC', warning: null };
  }

  if (/^\d{9,10}$/.test(clean)) {
    return {
      type: 'other',
      country: 'OT',
      warning: 'Documento ecuatoriano no verificable. Confirma el tipo, país o corrige el número antes de importar.',
    };
  }

  if (/^\d{6,8}$/.test(clean)) {
    return { type: 'foreign_id', country: 'VE', warning: 'Posible documento extranjero. Confirma el país emisor antes de importar.' };
  }

  if (/[a-zA-Z]/.test(clean)) {
    return { type: 'passport', country: 'OT', warning: null };
  }

  return { type: 'other', country: 'OT', warning: 'No se pudo identificar el tipo de documento. Revísalo antes de importar.' };
}

function buildName(lastName: string, firstName: string): string {
  const parts = [lastName.trim(), firstName.trim()].filter(Boolean);
  const decoded = parts.join(' ');
  const cleaned = decoded.replace(/\s+/g, ' ').trim();
  return cleaned
    .toLowerCase()
    .split(' ')
    .map((w) => (w ? w[0].toUpperCase() + w.slice(1) : ''))
    .join(' ');
}

function parseDate(value: string): string | null {
  const v = value.trim();
  if (!v || v === '0000-00-00') return null;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return null;

  const parsed = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === v ? v : null;
}

function parseSpreadsheetDate(value: unknown): string | null {
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }

  if (typeof value === 'string') return parseDate(value);

  return null;
}

function normalizeElosarDocumentType(value: unknown): DocumentType | null {
  const normalized = String(value ?? '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z_]/g, '');

  const types: Record<string, DocumentType> = {
    cedula: 'cedula',
    ceduladeidentidad: 'cedula',
    passport: 'passport',
    pasaporte: 'passport',
    foreignid: 'foreign_id',
    idextranjero: 'foreign_id',
    other: 'other',
    otro: 'other',
  };

  return types[normalized] ?? null;
}

function normalizeElosarGender(value: unknown): string | null {
  const normalized = String(value ?? '').trim().toLowerCase();

  return {
    male: 'male',
    masculino: 'male',
    m: 'male',
    female: 'female',
    femenino: 'female',
    f: 'female',
    other: 'other',
    otro: 'other',
  }[normalized] ?? null;
}

function spreadsheetCellText(value: unknown): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'object' && 'text' in value && typeof value.text === 'string') return value.text.trim();

  return String(value).trim();
}

/** Maps the exact, versioned patient export generated by Elosar. */
export function parseElosarPatientRows(rows: unknown[][]): PatientImportParseResult {
  if (rows.length < 2) return { rows: [], issues: [], format: 'elosar_xlsx' };

  const headers = rows[0].map((header) => normalizeHeader(spreadsheetCellText(header)));
  const col = (name: string): number => headers.findIndex((header) => header === name);
  const nameIdx = col('first_name');
  const idIdx = col('id_number');
  const birthIdx = col('birth_date');
  const phoneIdx = col('phone');
  const emailIdx = col('email');
  const addressIdx = col('address');
  const typeIdx = headers.findIndex((header) => header === 'tipo');
  const countryIdx = headers.findIndex((header) => header === 'pais');
  const genderIdx = headers.findIndex((header) => header === 'genero');
  const missingHeaders = [
    ['Nombre', nameIdx],
    ['Documento', idIdx],
    ['Fecha de nacimiento', birthIdx],
  ].filter(([, index]) => (index as number) < 0).map(([header]) => header);

  if (missingHeaders.length > 0) {
    throw new Error(`No corresponde a una exportación de pacientes de Elosar. Faltan las columnas: ${missingHeaders.join(', ')}.`);
  }

  const issues: ImportParseIssue[] = [];
  const mapped = rows.slice(1).map<PatientImportRow | null>((row, index) => {
    const line = index + 2;
    const values = row.map(spreadsheetCellText);
    if (!values.some(Boolean)) return null;

    const name = values[nameIdx] ?? '';
    const idNumber = values[idIdx] ?? '';
    const birthDate = parseSpreadsheetDate(row[birthIdx]);
    const documentType = normalizeElosarDocumentType(values[typeIdx]);
    const documentCountry = (values[countryIdx] ?? '').toUpperCase() || null;
    const detected = detectDocumentTypeAndCountry(idNumber);

    if (!name || !idNumber || !birthDate) {
      issues.push({ line, message: 'Falta o no es válida la fecha de nacimiento, el nombre o el documento.', kind: 'required_fields' });
    }

    return {
      source_line: line,
      name,
      id_number: idNumber,
      document_type: documentType ?? detected.type,
      document_country: documentCountry ?? detected.country,
      birth_date: birthDate ?? '',
      gender: normalizeElosarGender(values[genderIdx]),
      address: values[addressIdx] || null,
      email: values[emailIdx] || null,
      phone: values[phoneIdx] || null,
    };
  }).filter((row): row is PatientImportRow => row !== null);

  return { rows: mapped, issues, format: 'elosar_xlsx' };
}

/**
 * Parse only the patient formats that Elosar can validate end-to-end.
 * Legacy .xls files are HTML tables; current Elosar exports are XLSX files.
 */
export async function parsePatientImportFile(file: File): Promise<PatientImportParseResult> {
  const extension = file.name.split('.').pop()?.toLowerCase();

  if (extension === 'xlsx') {
    const { default: ExcelJS } = await import('exceljs');
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(await file.arrayBuffer());
    const worksheet = workbook.worksheets.find((sheet) => sheet.rowCount > 0);

    if (!worksheet) {
      throw new Error('La exportación XLSX no contiene una hoja con pacientes.');
    }

    const rows: unknown[][] = [];
    worksheet.eachRow({ includeEmpty: false }, (row) => {
      const values = row.values;
      rows.push(Array.isArray(values) ? values.slice(1) : Object.values(values));
    });

    return parseElosarPatientRows(rows);
  }

  const table = await parseHtmlTableFile(file);
  return parseLegacyPatientRows(table);
}

export function parseLegacyPatientRows(rows: string[][]): PatientImportParseResult {
  if (rows.length < 2) return { rows: [], issues: [], format: 'legacy_html_xls' };

  const headers = rows[0].map(normalizeHeader);
  const dataRows = rows.slice(1);

  const col = (name: string): number => headers.findIndex((h) => h === name);

  const lastNameIdx = col('last_name');
  const firstNameIdx = col('first_name');
  const idIdx = col('id_number');
  const birthIdx = col('birth_date');
  const phoneIdx = col('phone');
  const mobileIdx = col('mobile');
  const emailIdx = col('email');
  const addressIdx = col('address');

  const requiredHeaders: Array<[string, number]> = [
    ['Apellidos', lastNameIdx],
    ['Nombres', firstNameIdx],
    ['ID', idIdx],
    ['Nacimiento', birthIdx],
  ];
  const missingHeaders = requiredHeaders.filter(([, index]) => index < 0).map(([header]) => header);

  if (missingHeaders.length > 0) {
    throw new Error(`No corresponde al formato de pacientes esperado. Faltan las columnas: ${missingHeaders.join(', ')}.`);
  }

  const issues: ImportParseIssue[] = [];
  const mapped: PatientImportRow[] = dataRows
    .map<PatientImportRow | null>((row, index) => {
      if (!row.some((cell) => cell.trim() !== '')) return null;

      const idNumber = idIdx >= 0 ? row[idIdx]?.trim() ?? '' : '';
      const { type, country, warning } = detectDocumentTypeAndCountry(idNumber);

      const name =
        lastNameIdx >= 0 || firstNameIdx >= 0
          ? buildName(row[lastNameIdx] ?? '', row[firstNameIdx] ?? '')
          : '';

      const birthDate = birthIdx >= 0 ? parseDate(row[birthIdx] ?? '') : null;
      const phone = mobileIdx >= 0 ? row[mobileIdx]?.trim() : phoneIdx >= 0 ? row[phoneIdx]?.trim() : '';

      if (!name || !idNumber || !birthDate) {
        issues.push({ line: index + 2, message: 'Falta o no es válida la fecha de nacimiento, el nombre o el documento.', kind: 'required_fields' });
      }
      if (warning) issues.push({ line: index + 2, message: warning, kind: 'document' });

      return {
        source_line: index + 2,
        name,
        id_number: idNumber,
        document_type: type,
        document_country: country,
        birth_date: birthDate ?? '',
        gender: null,
        address: addressIdx >= 0 ? row[addressIdx]?.trim() || null : null,
        email: emailIdx >= 0 ? row[emailIdx]?.trim() || null : null,
        phone: phone || null,
      };
    })
    .filter((row): row is PatientImportRow => row !== null);

  return { rows: mapped, issues, format: 'legacy_html_xls' };
}

/** Backwards-compatible mapping for the appointment importer. */
export function mapPatientRows(rows: string[][]): PatientImportRow[] {
  return parseLegacyPatientRows(rows).rows;
}

export function mapAppointmentRows(rows: string[][]): AppointmentImportRow[] {
  if (rows.length < 2) return [];

  const headers = rows[0].map(normalizeHeader);
  const dataRows = rows.slice(1);

  const col = (name: string): number => headers.findIndex((h) => h === name);

  const dateIdx = headers.findIndex((h) => h.includes('fecha'));
  const timeIdx = headers.findIndex((h) => h.includes('hora'));
  const idIdx = col('id_number');
  const statusIdx = headers.findIndex((h) => h.includes('status') || h.includes('estado'));
  const serviceIdx = headers.findIndex((h) => h.includes('servicio'));
  const establishmentIdx = headers.findIndex((h) => h.includes('establecimiento'));
  const notesIdx = headers.findIndex((h) => h.includes('observacion'));

  return dataRows
    .map((row) => {
      const date = dateIdx >= 0 ? row[dateIdx]?.trim() : '';
      const time = timeIdx >= 0 ? row[timeIdx]?.trim() : '';
      const idNumber = idIdx >= 0 ? row[idIdx]?.trim() ?? '' : '';
      const { type, country } = detectDocumentTypeAndCountry(idNumber);

      const appointmentDate = date && time ? `${date} ${time}` : date;

      return {
        appointment_date: appointmentDate,
        id_number: idNumber,
        document_type: type,
        document_country: country,
        status: statusIdx >= 0 ? row[statusIdx]?.trim() || null : null,
        service_name: serviceIdx >= 0 ? row[serviceIdx]?.trim() || null : null,
        channel: establishmentIdx >= 0 ? row[establishmentIdx]?.trim() || null : null,
        notes: notesIdx >= 0 ? row[notesIdx]?.trim() || null : null,
      };
    })
    .filter((r) => r.appointment_date !== '' && r.id_number !== '');
}
