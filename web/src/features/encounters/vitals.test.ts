import { describe, expect, it } from 'vitest';
import { bmiClassification, computeBmi } from './vitals';

describe('computeBmi', () => {
  it('computes BMI from kilograms and centimetres', () => {
    expect(computeBmi('70', '175')).toBe('22.9');
  });

  it('returns null rather than a number when the input cannot yield one', () => {
    expect(computeBmi('', '175')).toBeNull();
    expect(computeBmi('70', '')).toBeNull();
    expect(computeBmi('70', '0')).toBeNull();
    expect(computeBmi('abc', '175')).toBeNull();
    // A negative weight is data entry noise, not a person.
    expect(computeBmi('-70', '175')).toBeNull();
  });
});

describe('bmiClassification', () => {
  // The band edges are the whole point: 25.0 is Sobrepeso, not Normal.
  it.each([
    ['17.0', 'Bajo peso'],
    ['18.4', 'Bajo peso'],
    ['18.5', 'Normal'],
    ['24.9', 'Normal'],
    ['25.0', 'Sobrepeso'],
    ['29.9', 'Sobrepeso'],
    ['30.0', 'Obesidad grado I (leve)'],
    ['34.9', 'Obesidad grado I (leve)'],
    ['35.0', 'Obesidad grado II (moderada)'],
    ['39.9', 'Obesidad grado II (moderada)'],
    ['40.0', 'Obesidad grado III (severa)'],
    ['52.3', 'Obesidad grado III (severa)'],
  ])('classifies %s as %s', (bmi, expected) => {
    expect(bmiClassification(bmi)).toBe(expected);
  });

  it('classifies nothing when there is no BMI to classify', () => {
    expect(bmiClassification(null)).toBeNull();
    expect(bmiClassification('')).toBeNull();
    expect(bmiClassification('abc')).toBeNull();
  });
});
