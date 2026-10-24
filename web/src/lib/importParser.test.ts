// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { parseElosarPatientRows, parseHtmlTableFile, parseLegacyPatientRows, parsePatientImportFile } from './importParser';

describe('parseLegacyPatientRows', () => {
  it('ignores legacy Ciudad and leaves address null when Dirección is absent', () => {
    const rows = [
      ['Historia', 'ID', 'Apellidos', 'Nombres', 'Nacimiento', 'Edad', 'Teléfonos', 'Celular', 'E-mail', 'Ciudad'],
      ['001', '1712345678', 'Pérez', 'María', '1990-05-15', '36', '', '0991234567', 'maria@example.com', 'Ibarra'],
    ];

    const { rows: mapped, issues } = parseLegacyPatientRows(rows);
    const [patient] = mapped;

    expect(patient).toMatchObject({
      name: 'Pérez María',
      birth_date: '1990-05-15',
      address: null,
      gender: null,
    });
    expect(issues).toEqual([
      {
        line: 2,
        message: 'Documento ecuatoriano no verificable. Confirma el tipo, país o corrige el número antes de importar.',
        kind: 'document',
      },
    ]);
  });

  it('maps Dirección from a legacy HTML export without using Ciudad as a fallback', () => {
    const { rows: mapped } = parseLegacyPatientRows([
      ['ID', 'Apellidos', 'Nombres', 'Nacimiento', 'Ciudad', 'Dirección'],
      ['1712345678', 'Pérez', 'María', '1990-05-15', 'Ibarra', 'Av. Mariano Acosta 123'],
    ]);

    expect(mapped[0]).toMatchObject({
      name: 'Pérez María',
      address: 'Av. Mariano Acosta 123',
    });
    expect(mapped[0]).not.toHaveProperty('city');
  });

  it('maps Dirección from an Elosar XLSX header', () => {
    const { rows: mapped } = parseElosarPatientRows([
      ['Nombre', 'Documento', 'Fecha de nacimiento', 'Dirección'],
      ['María Pérez', '1712345678', '1990-05-15', 'Av. Mariano Acosta 123'],
    ]);

    expect(mapped[0]).toMatchObject({
      name: 'María Pérez',
      address: 'Av. Mariano Acosta 123',
    });
    expect(mapped[0]).not.toHaveProperty('city');
  });

  it('blocks a table that is not the supported legacy patient format', () => {
    expect(() => parseLegacyPatientRows([['Nombre', 'Documento'], ['María', '1712345678']])).toThrow(
      /Faltan las columnas/i,
    );
  });

  it('decodes the Windows-1252 HTML .xls variant used by the legacy export', async () => {
    const prefix = new TextEncoder().encode('<table><tr><th>ID</th><th>Apellidos</th></tr><tr><td>1</td><td>P');
    const suffix = new TextEncoder().encode('rez</td></tr></table>');
    const file = new File([prefix, new Uint8Array([0xe9]), suffix], 'pacientes.xls', { type: 'application/vnd.ms-excel' });

    await expect(parseHtmlTableFile(file)).resolves.toEqual([
      ['ID', 'Apellidos'],
      ['1', 'Pérez'],
    ]);
  });

  it('keeps a non-empty malformed row visible instead of silently dropping it', () => {
    const { rows, issues } = parseLegacyPatientRows([
      ['ID', 'Apellidos', 'Nombres', 'Nacimiento', 'Ciudad'],
      ['', '', '', '1990-02-30', 'Ibarra'],
    ]);

    expect(rows).toHaveLength(1);
    expect(rows[0]?.source_line).toBe(2);
    expect(issues).toContainEqual({
      line: 2,
      message: 'Falta o no es válida la fecha de nacimiento, el nombre o el documento.',
      kind: 'required_fields',
    });
  });

  it('reads the XLSX format exported by Elosar without shifting birth dates', async () => {
    const { default: ExcelJS } = await import('exceljs');
    const workbook = new ExcelJS.Workbook();
    const sheet = workbook.addWorksheet('Pacientes');

    sheet.addRow([
      'Nombre', 'Documento', 'Tipo', 'País', 'Fecha de nacimiento', 'Edad',
      'Género', 'Teléfono', 'Email', 'Dirección', 'Última visita',
    ]);
    sheet.addRow([
      'Araujo Quichimbo Angie Salome', '1729257186', 'Cédula', 'EC', new Date(1990, 6, 28), 36,
      'Otro', '0987306092', 'angie@example.com', 'Av. Atahualpa 456', null,
    ]);

    const buffer = await workbook.xlsx.writeBuffer();
    const file = new File([buffer as ArrayBuffer], 'pacientes-2026-08-12.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    await expect(parsePatientImportFile(file)).resolves.toEqual({
      format: 'elosar_xlsx',
      issues: [],
      rows: [
        {
          source_line: 2,
          name: 'Araujo Quichimbo Angie Salome',
          id_number: '1729257186',
          document_type: 'cedula',
          document_country: 'EC',
          birth_date: '1990-07-28',
          gender: 'other',
          phone: '0987306092',
          email: 'angie@example.com',
          address: 'Av. Atahualpa 456',
        },
      ],
    });
  });

});
