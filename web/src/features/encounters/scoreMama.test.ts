import { describe, expect, it } from 'vitest';
import { scoreMama, type ScoreMamaInput } from './scoreMama';

/** Una toma completa y normal: todo en su columna 0. Base para variar un solo valor. */
function normal(over: Partial<ScoreMamaInput> = {}): ScoreMamaInput {
  return {
    temperature: 36.5,
    systolic: 120,
    diastolic: 70,
    heartRate: 80,
    respiratoryRate: 16,
    oxygenSaturation: 97,
    oxygenTherapy: 'ambient_air',
    consciousness: 'alert',
    urineOutput: { kind: 'not_quantifiable' },
    ...over,
  };
}

describe('scoreMama', () => {
  // La prueba que valida toda la tabla de una vez. El propio protocolo trae este
  // caso resuelto (página 18 impresa) con sus puntajes parciales y su total.
  // Si alguien reordena una columna, esta suma deja de dar 3.
  it('reproduces the worked example printed in the MSP protocol', () => {
    const result = scoreMama({
      temperature: 36.5,
      systolic: 140,
      diastolic: 80,
      heartRate: 105,
      respiratoryRate: 22,
      oxygenSaturation: 96,
      oxygenTherapy: 'ambient_air',
      consciousness: 'alert',
      urineOutput: { kind: 'not_quantifiable' },
    });

    expect(result.points).toEqual({
      temperature: 0,
      systolic: 1,
      diastolic: 0,
      heartRate: 1,
      respiratoryRate: 1,
      oxygenSaturation: 0,
      oxygenTherapy: 0,
      consciousness: 0,
      urineOutput: 0,
    });
    expect(result.total).toBe(3);
    expect(result.level).toBe('treat_and_refer');
    expect(result.complete).toBe(true);
  });

  // El error más fácil al transcribir la tabla: esa fila tiene la columna 2
  // vacía del lado izquierdo, así que 35-35.9 vale 1.
  it('scores a temperature of 35-35.9 as 1, not 2', () => {
    expect(scoreMama(normal({ temperature: 35.5 })).points.temperature).toBe(1);
    expect(scoreMama(normal({ temperature: 34.9 })).points.temperature).toBe(3);
  });

  // La regla que no es una suma. Una sistólica de 65 puntúa 3 ella sola: total 3,
  // que cae en la banda 2-4, pero es emergencia obstétrica igual.
  it('escalates to emergency on any single parameter at 3, however low the total', () => {
    const result = scoreMama(normal({ systolic: 65 }));

    expect(result.total).toBe(3);
    expect(result.hasParameterAtThree).toBe(true);
    expect(result.level).toBe('emergency');
  });

  it('still escalates to emergency on 5 points with nothing at 3', () => {
    // 110-129 lpm = 2, 25-29 rpm = 2, responde a la voz = 1 → 5 sin ningún 3.
    const result = scoreMama(
      normal({ heartRate: 115, respiratoryRate: 26, consciousness: 'responds_to_voice' }),
    );

    expect(result.total).toBe(5);
    expect(result.hasParameterAtThree).toBe(false);
    expect(result.level).toBe('emergency');
  });

  it.each([
    [0, 'normal'],
    [1, 'reevaluate'],
    [2, 'treat_and_refer'],
    [4, 'treat_and_refer'],
  ] as const)('maps a total of %i to the %s band', (total, level) => {
    // Sube el puntaje sólo con consciencia (1) y FC (0-2) para no tocar tres.
    const byTotal: Record<number, Partial<ScoreMamaInput>> = {
      0: {},
      1: { consciousness: 'responds_to_voice' },
      2: { heartRate: 115 },
      4: { heartRate: 115, respiratoryRate: 26 },
    };
    const result = scoreMama(normal(byTotal[total]));

    expect(result.total).toBe(total);
    expect(result.level).toBe(level);
  });

  // "No cuantificable" es un valor, no una ausencia: puntúa 0 y no deja la toma
  // incompleta. Confundirlos haría que toda paciente ambulatoria saliera incompleta.
  it('treats a non-quantifiable urine output as a real 0, not as missing', () => {
    const result = scoreMama(normal({ urineOutput: { kind: 'not_quantifiable' } }));

    expect(result.points.urineOutput).toBe(0);
    expect(result.complete).toBe(true);
  });

  it('scores measured urine output against the oliguria cut-offs', () => {
    expect(scoreMama(normal({ urineOutput: { kind: 'measured', mlPerHour: 8 } })).points.urineOutput).toBe(3);
    expect(scoreMama(normal({ urineOutput: { kind: 'measured', mlPerHour: 25 } })).points.urineOutput).toBe(2);
    expect(scoreMama(normal({ urineOutput: { kind: 'measured', mlPerHour: 60 } })).points.urineOutput).toBe(0);
  });

  // Un parámetro que no se tomó no puede puntuar. Contarlo como 0 rebajaría el
  // puntaje de una paciente a la que no se le midió lo que estaba mal.
  it('never counts a missing parameter as a zero', () => {
    const result = scoreMama({ temperature: 36.5, systolic: 120 });

    expect(result.total).toBe(0);
    expect(result.complete).toBe(false);
    expect(result.missing).toContain('consciousness');
    expect(result.points.consciousness).toBeNull();
  });

  it('reports how far a partial score can be trusted', () => {
    const result = scoreMama(normal({ oxygenSaturation: null, urineOutput: null }));

    expect(result.complete).toBe(false);
    expect(result.missing).toEqual(['oxygenSaturation', 'urineOutput']);
  });

  describe('cut-offs that decide a referral', () => {
    it.each([
      [139, 0],
      [140, 1],
      [149, 1],
      [150, 2],
      [159, 2],
      [160, 3],
      [90, 0],
      [89, 1],
      [79, 2],
      [70, 3],
    ])('scores a systolic of %i as %i', (systolic, expected) => {
      expect(scoreMama(normal({ systolic })).points.systolic).toBe(expected);
    });

    it.each([
      [94, 0],
      [93, 1],
      [90, 2],
      [88, 3],
    ])('scores an oxygen saturation of %i as %i', (spo2, expected) => {
      expect(scoreMama(normal({ oxygenSaturation: spo2 })).points.oxygenSaturation).toBe(expected);
    });

    it.each([
      ['ambient_air', 0],
      ['nasal_cannula', 2],
      ['mask_or_higher', 3],
    ] as const)('scores %s oxygen therapy as %i', (therapy, expected) => {
      expect(scoreMama(normal({ oxygenTherapy: therapy })).points.oxygenTherapy).toBe(expected);
    });

    it.each([
      ['alert', 0],
      ['responds_to_voice', 1],
      ['responds_to_pain', 2],
      ['unresponsive', 3],
    ] as const)('scores a consciousness of %s as %i', (level, expected) => {
      expect(scoreMama(normal({ consciousness: level })).points.consciousness).toBe(expected);
    });
  });
});
