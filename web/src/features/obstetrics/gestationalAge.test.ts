import { describe, expect, it } from 'vitest';
import {
  GESTATIONAL_AGE_PROTOCOL,
  eddFromLmp,
  eddFromUltrasound,
  electiveCesareanDate,
  gestationalAgeAt,
  resolveDating,
} from './gestationalAge';

/**
 * Espejo de `medico-backend/tests/Unit/GestationalAgeTest.php`. Los mismos casos
 * resueltos en las dos suites: si una implementación deriva de la otra, aquí se
 * pone algo rojo.
 */
describe('edad gestacional', () => {
  it('pone la FPP 280 días después del primer día de la última menstruación', () => {
    expect(eddFromLmp('2026-01-01')).toBe('2026-10-08');
  });

  it('reporta la edad en semanas Y días, como se escribe en una historia', () => {
    const age = gestationalAgeAt({ lmpDate: '2026-01-01', datingSource: 'lmp' }, '2026-08-17');

    expect(age.days).toBe(228);
    expect(age.weeks).toBe(32);
    expect(age.remainderDays).toBe(4);
    expect(age.label).toBe('32+4');
  });

  /** El borde que una implementación "solo semanas" se come sin enterarse. */
  it('cambia de semana el día exacto, no antes', () => {
    const at = (days: number) => {
      const on = new Date(Date.UTC(2026, 0, 1) + days * 86_400_000).toISOString().slice(0, 10);
      return gestationalAgeAt({ lmpDate: '2026-01-01', datingSource: 'lmp' }, on).label;
    };

    expect(at(223)).toBe('31+6');
    expect(at(224)).toBe('32+0');
  });

  it('data desde una ecografía temprana contando el resto de los 280 días', () => {
    // Un feto de 12+0 el 2026-03-01 tiene 280 - 84 = 196 días por delante.
    expect(eddFromUltrasound('2026-03-01', 12, 0)).toBe('2026-09-13');
  });

  it('reporta la discrepancia entre las dos dataciones, con signo', () => {
    const resolved = resolveDating({
      lmpDate: '2026-01-01',
      ultrasoundDate: '2026-03-01',
      ultrasoundWeeks: 10,
      ultrasoundDays: 0,
      datingSource: 'lmp',
    });

    expect(resolved.eddByLmp).toBe('2026-10-08');
    expect(resolved.eddByUltrasound).toBe('2026-09-27');
    // Positivo: la ecografía data el embarazo más avanzado que la FUM.
    expect(resolved.discrepancyDays).toBe(11);
  });

  it('obedece al médico en vez de redatar por su cuenta', () => {
    const pregnancy = {
      lmpDate: '2026-01-01',
      ultrasoundDate: '2026-03-01',
      ultrasoundWeeks: 10,
      ultrasoundDays: 0,
    };

    expect(resolveDating({ ...pregnancy, datingSource: 'lmp' }).edd).toBe('2026-10-08');
    expect(resolveDating({ ...pregnancy, datingSource: 'ultrasound' }).edd).toBe('2026-09-27');
  });

  it('cae a la datación que existe cuando la pedida no tiene datos', () => {
    const resolved = resolveDating({
      ultrasoundDate: '2026-03-01',
      ultrasoundWeeks: 10,
      datingSource: 'lmp',
    });

    expect(resolved.edd).not.toBeNull();
    // Informa la que usó, no la que le pidieron.
    expect(resolved.source).toBe('ultrasound');
    expect(resolved.missing).toEqual(['lmp_date']);
  });

  it('parte los trimestres en sus días exactos', () => {
    const at = (days: number) => {
      const on = new Date(Date.UTC(2026, 0, 1) + days * 86_400_000).toISOString().slice(0, 10);
      return gestationalAgeAt({ lmpDate: '2026-01-01', datingSource: 'lmp' }, on).trimester;
    };

    expect(at(97)).toBe(1); // 13+6
    expect(at(98)).toBe(2); // 14+0
    expect(at(195)).toBe(2); // 27+6
    expect(at(196)).toBe(3); // 28+0
  });

  /** Un parámetro ausente no vale 0, igual que en el Score MAMÁ. */
  it('no reporta cero semanas para un embarazo que no puede datar', () => {
    const age = gestationalAgeAt({ datingSource: 'lmp' }, '2026-08-17');

    expect(age.days).toBeNull();
    expect(age.label).toBeNull();
    expect(age.isValid).toBe(false);
    expect(age.missing).toEqual(['lmp_date', 'ultrasound']);
  });

  it('nunca reporta una edad negativa para una FUM futura', () => {
    const age = gestationalAgeAt({ lmpDate: '2026-06-01', datingSource: 'lmp' }, '2026-05-01');

    expect(age.days).toBeNull();
    expect(age.isValid).toBe(false);
  });

  it('congela la edad de un embarazo que ya terminó', () => {
    const ended = {
      lmpDate: '2026-01-01',
      datingSource: 'lmp' as const,
      status: 'completed',
      outcomeDate: '2026-09-01',
    };

    expect(gestationalAgeAt(ended, '2027-03-01').label).toBe(
      gestationalAgeAt(ended, '2026-09-01').label,
    );
  });

  it('marca a término en 37+0 y postérmino en 42+0, exactos', () => {
    const at = (days: number) => {
      const on = new Date(Date.UTC(2026, 0, 1) + days * 86_400_000).toISOString().slice(0, 10);
      return gestationalAgeAt({ lmpDate: '2026-01-01', datingSource: 'lmp' }, on);
    };

    expect(at(258).isTerm).toBe(false); // 36+6
    expect(at(259).isTerm).toBe(true); // 37+0
    expect(at(293).isPostTerm).toBe(false); // 41+6
    expect(at(294).isPostTerm).toBe(true); // 42+0
  });

  /** El protocolo se nombra igual en los dos lados o el sello deja de significar nada. */
  it('nombra el mismo protocolo que el servidor', () => {
    expect(GESTATIONAL_AGE_PROTOCOL).toBe('NAEGELE-280');
  });

  /**
   * Confirmado con el médico a partir de dos ejemplos reales del sistema que se
   * está migrando, ambos con exactamente 7 días de diferencia entre FPP y FPC:
   * la cesárea electiva no se programa antes de las 39 semanas.
   */
  it('pone la cesárea electiva 7 días antes de la FPP', () => {
    expect(electiveCesareanDate('2027-02-19')).toBe('2027-02-12');
    expect(electiveCesareanDate('2023-11-11')).toBe('2023-11-04');
  });

  it('no inventa una fecha de cesárea sin FPP', () => {
    expect(electiveCesareanDate(null)).toBeNull();
  });
});
