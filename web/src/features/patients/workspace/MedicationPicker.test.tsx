import { describe, expect, it } from 'vitest';
import {
  emptyRow,
  missingDispensingQuantity,
  prescriptionRowToPayload,
  renameMedication,
  rowFromCatalog,
  rowFromHistory,
} from './MedicationPicker';
import type { MedicationCatalogItem, RecordPrescription } from '@/api/types';

describe('prescriptionRowToPayload', () => {
  it('sends only what the doctor typed for a three-field prescription', () => {
    const payload = prescriptionRowToPayload({
      ...emptyRow(),
      medication_name: '  Betametasona crema  ',
      quantity: '1',
      instructions: '  Aplicar dos veces al día.  ',
    });

    expect(payload).toEqual({
      medication_name: 'Betametasona crema',
      generic_name: undefined,
      concentration: undefined,
      dosage: undefined,
      route: undefined,
      frequency: undefined,
      duration_days: undefined,
      quantity: 1,
      instructions: 'Aplicar dos veces al día.',
      is_controlled: false,
    });
  });

  it('never invents a posology the doctor could not see', () => {
    // The schedule fields left the screen on 2026-09-21. A blank row must not
    // carry "1 tableta / cada 8 horas / 7 días" onto a signed prescription.
    const payload = prescriptionRowToPayload({
      ...emptyRow(),
      medication_name: 'Suero fisiológico',
      quantity: '2',
    });

    expect(payload.dosage).toBeUndefined();
    expect(payload.frequency).toBeUndefined();
    expect(payload.duration_days).toBeUndefined();
    expect(payload.route).toBeUndefined();
  });

  it('carries the posology of a repeated prescription verbatim', () => {
    // "Repetir la última receta" reproduces a prescription that WAS reviewed and
    // signed at the time, so its posology travels with it even though the fields
    // are no longer on screen.
    const stored: RecordPrescription = {
      id: 7,
      medication_name: 'Paracetamol',
      generic_name: 'Paracetamol',
      concentration: '500mg',
      dosage: '1 tableta',
      route: 'oral',
      frequency: 'cada 8 horas',
      duration_days: 5,
      quantity: 15,
      instructions: 'Tomar con alimentos.',
      is_controlled: false,
    };

    const payload = prescriptionRowToPayload(rowFromHistory(stored));

    expect(payload).toMatchObject({
      medication_name: 'Paracetamol',
      generic_name: 'Paracetamol',
      concentration: '500mg',
      dosage: '1 tableta',
      route: 'oral',
      frequency: 'cada 8 horas',
      duration_days: 5,
      quantity: 15,
      instructions: 'Tomar con alimentos.',
    });
  });

  it('keeps the controlled-substance flag, which drives the special pad number', () => {
    const payload = prescriptionRowToPayload({
      ...emptyRow(),
      medication_name: 'Clonazepam',
      quantity: '30',
      is_controlled: true,
    });

    expect(payload.is_controlled).toBe(true);
  });

  it('leaves quantity undefined rather than sending NaN when it is blank', () => {
    // The server rejects a blank quantity; it must arrive as absent, not as NaN.
    expect(prescriptionRowToPayload({ ...emptyRow(), medication_name: 'X' }).quantity).toBeUndefined();
  });
});

describe('emptyRow', () => {
  it('starts with no posology at all', () => {
    const row = emptyRow();

    expect(row.dosage).toBe('');
    expect(row.frequency).toBe('');
    expect(row.duration_days).toBe('');
    expect(row.route).toBe('');
    expect(row.quantity).toBe('');
  });
});

describe('renameMedication', () => {
  /** A row as "repetir última receta" leaves it: every carried field populated. */
  function repeatedRow() {
    return rowFromHistory({
      id: 7,
      medication_name: 'Paracetamol',
      generic_name: 'Paracetamol',
      concentration: '500mg',
      dosage: '1 tableta',
      route: 'oral',
      frequency: 'cada 8 horas',
      duration_days: 5,
      quantity: 15,
      instructions: 'Tomar con alimentos.',
      is_controlled: false,
    });
  }

  // The leak this closes: the carried fields have no input on screen, so typing
  // a new drug over a repeated row would sign "IBUPROFENO 500MG · 1 tableta,
  // cada 8 horas, por 5 días" — the previous drug's numbers, with nowhere to
  // notice them.
  it('drops everything that described the previous medication', () => {
    const renamed = renameMedication(repeatedRow(), 'Ibuprofeno');

    expect(renamed.medication_name).toBe('Ibuprofeno');
    expect(renamed.generic_name).toBe('');
    expect(renamed.concentration).toBe('');
    expect(renamed.dosage).toBe('');
    expect(renamed.frequency).toBe('');
    expect(renamed.duration_days).toBe('');
    expect(renamed.route).toBe('');
    expect(renamed.catalog_id).toBeNull();
  });

  it('keeps what the doctor wrote themselves', () => {
    const renamed = renameMedication(repeatedRow(), 'Ibuprofeno');

    // Quantity and instructions describe THIS prescription, not the old drug.
    expect(renamed.quantity).toBe('15');
    expect(renamed.instructions).toBe('Tomar con alimentos.');
  });

  it('does not drop the carry when the name has not actually changed', () => {
    const renamed = renameMedication(repeatedRow(), '  Paracetamol  ');

    expect(renamed.dosage).toBe('1 tableta');
    expect(renamed.concentration).toBe('500mg');
  });

  it('produces a payload with none of the previous medication in it', () => {
    const payload = prescriptionRowToPayload(renameMedication(repeatedRow(), 'Ibuprofeno'));

    expect(payload.medication_name).toBe('Ibuprofeno');
    expect(payload.concentration).toBeUndefined();
    expect(payload.dosage).toBeUndefined();
    expect(payload.frequency).toBeUndefined();
    expect(payload.duration_days).toBeUndefined();
    expect(payload.route).toBeUndefined();
    expect(payload.generic_name).toBeUndefined();
  });
});

describe('missingDispensingQuantity', () => {
  function named(quantity: string) {
    return { ...emptyRow(), medication_name: 'Betametasona crema', quantity };
  }

  it('accepts a whole number of units', () => {
    expect(missingDispensingQuantity([named('30')])).toBe(false);
    expect(missingDispensingQuantity([named('  30  ')])).toBe(false);
  });

  it.each(['', '   ', 'treinta', '1 caja', '1.5', '-2', '0'])(
    'rejects %o, which the server would answer with a 422',
    (quantity) => {
      // `inputMode="numeric"` is a keyboard hint, not a restriction. Anything
      // that is not a whole number becomes NaN, serializes as null, and comes
      // back as a validation error about a field the doctor believes they
      // filled in — "the button does nothing", through the last numeric field
      // the form has.
      expect(missingDispensingQuantity([named(quantity)])).toBe(true);
    },
  );

  it('ignores a row with no medication name, which is just an unfilled row', () => {
    expect(missingDispensingQuantity([emptyRow()])).toBe(false);
  });
});

describe('rowFromCatalog', () => {
  /** A catalog entry with every posology default filled in, as the API returns it. */
  function catalogItem(over: Partial<MedicationCatalogItem> = {}): MedicationCatalogItem {
    return {
      id: 5,
      workspace_id: 1,
      is_global: false,
      name: 'Paracetamol',
      concentration: '500mg',
      default_dose_quantity: 2,
      default_dose_unit: 'tablet',
      default_dose_unit_label: 'tableta',
      default_frequency: 'every_8_hours',
      default_frequency_label: 'cada 8 horas',
      default_duration_days: 7,
      route: 'oral',
      route_label: 'Oral',
      is_controlled: false,
      default_instructions: 'Tomar con alimentos.',
      suggested_dosage: '2 tabletas',
      ...over,
    } as MedicationCatalogItem;
  }

  // The clinical risk this whole change had to avoid: the schedule fields left
  // the screen, so a catalog default can no longer be reviewed before it is
  // signed. Carrying one through would print a posology on a signed
  // prescription that the doctor never saw.
  it('takes the identity and instructions of a catalog pick, never its posology', () => {
    const row = rowFromCatalog(catalogItem());

    expect(row.medication_name).toBe('Paracetamol');
    expect(row.concentration).toBe('500mg');
    expect(row.instructions).toBe('Tomar con alimentos.');
    expect(row.catalog_id).toBe(5);

    expect(row.dosage).toBe('');
    expect(row.frequency).toBe('');
    expect(row.duration_days).toBe('');
    expect(row.route).toBe('');
    expect(row.quantity).toBe('');
  });

  it('carries the controlled flag, which drives the special pad number', () => {
    expect(rowFromCatalog(catalogItem({ is_controlled: true })).is_controlled).toBe(true);
  });

  // And the payload built from it must stay posology-free end to end, not just
  // the row: this is the assertion that actually reaches the server.
  it('produces a payload with no posology in it', () => {
    const payload = prescriptionRowToPayload({ ...rowFromCatalog(catalogItem()), quantity: '20' });

    expect(payload.dosage).toBeUndefined();
    expect(payload.frequency).toBeUndefined();
    expect(payload.duration_days).toBeUndefined();
    expect(payload.route).toBeUndefined();
    expect(payload.medication_name).toBe('Paracetamol');
    expect(payload.quantity).toBe(20);
  });
});
