import { downloadBlob } from './download';

/**
 * Workbook builder for the report screens.
 *
 * Amounts travel from the API as strings (bcmath) but are written as real
 * numbers with a currency format, so the accountant can sum a column instead
 * of re-typing it — that is the whole point of exporting to Excel rather than
 * to a CSV of text.
 */

export type CellFormat = 'money' | 'int' | 'decimal' | 'percent' | 'date' | 'text';

export interface SheetColumn {
  header: string;
  key: string;
  width?: number;
  format?: CellFormat;
}

export interface SheetSpec {
  /** Tab name. Excel caps it at 31 chars and forbids : \ / ? * [ ]. */
  name: string;
  columns: SheetColumn[];
  rows: Record<string, unknown>[];
  /** Optional note printed above the header (period, scope, filters…). */
  caption?: string;
}

const NUMBER_FORMATS: Record<CellFormat, string | undefined> = {
  money: '"$"#,##0.00',
  int: '#,##0',
  decimal: '#,##0.00',
  percent: '0.0"%"',
  date: 'dd/mm/yyyy',
  text: undefined,
};

/** Excel rejects these characters in a tab name, and truncates past 31 chars. */
function safeSheetName(name: string, index: number): string {
  const cleaned = name.replace(/[:\\/?*[\]]/g, ' ').trim();
  return (cleaned || `Hoja ${index + 1}`).slice(0, 31);
}

/** "305.00" → 305. Anything unparseable stays as-is so nothing is silently lost. */
function toNumber(value: unknown): number | unknown {
  if (typeof value === 'number') return value;
  if (typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))) {
    return Number(value);
  }
  return value;
}

/** A date-only string is built in local time; see formatDate in i18n/es. */
function toDate(value: unknown): Date | unknown {
  if (value instanceof Date) return value;
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return new Date(`${value}T00:00:00`);
  }
  return value;
}

/**
 * Build and download an .xlsx. Each sheet gets a bold frozen header, sized
 * columns and per-column number formats.
 */
export async function downloadWorkbook(filename: string, sheets: SheetSpec[]): Promise<void> {
  // Loaded on demand: exceljs is ~900 kB, and bundling it statically doubled
  // the app's main chunk for every visitor, most of whom never export.
  const { default: ExcelJS } = await import('exceljs');

  const workbook = new ExcelJS.Workbook();
  workbook.creator = 'Elosar';
  workbook.created = new Date();

  sheets.forEach((spec, index) => {
    const sheet = workbook.addWorksheet(safeSheetName(spec.name, index));
    let headerRowIndex = 1;

    if (spec.caption) {
      const caption = sheet.addRow([spec.caption]);
      caption.font = { italic: true, size: 10, color: { argb: 'FF6B7280' } };
      sheet.addRow([]);
      headerRowIndex = 3;
    }

    const header = sheet.addRow(spec.columns.map((c) => c.header));
    header.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    header.alignment = { vertical: 'middle' };
    header.height = 20;
    header.eachCell((cell) => {
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0F766E' } };
    });

    spec.rows.forEach((row) => {
      sheet.addRow(
        spec.columns.map((column) => {
          const raw = row[column.key];
          if (raw === null || raw === undefined) return null;
          if (column.format === 'date') return toDate(raw);
          if (column.format && column.format !== 'text') return toNumber(raw);
          return raw;
        }),
      );
    });

    spec.columns.forEach((column, i) => {
      const excelColumn = sheet.getColumn(i + 1);
      excelColumn.width = column.width ?? Math.max(12, column.header.length + 4);
      const numFmt = NUMBER_FORMATS[column.format ?? 'text'];
      if (numFmt) {
        // Skip the caption/header rows when applying the format.
        excelColumn.eachCell({ includeEmpty: false }, (cell, rowNumber) => {
          if (rowNumber > headerRowIndex) cell.numFmt = numFmt;
        });
      }
    });

    sheet.views = [{ state: 'frozen', ySplit: headerRowIndex }];
  });

  const buffer = await workbook.xlsx.writeBuffer();
  downloadBlob(
    new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
    filename,
  );
}

/** `reporte-financiero-2026-07-19.xlsx` */
export function stampedFilename(base: string): string {
  const now = new Date();
  const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
  return `${base}-${date}.xlsx`;
}
