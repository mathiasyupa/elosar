import { describe, expect, it } from 'vitest';
import { safeNextPath } from './nextPath';

describe('safeNextPath', () => {
  it('returns to a page inside Elosar', () => {
    expect(safeNextPath('/pacientes/5163/atencion?record=2573')).toBe('/pacientes/5163/atencion?record=2573');
  });

  it('never leaves Elosar', () => {
    expect(safeNextPath('https://evil.example')).toBeNull();
    expect(safeNextPath('//evil.example')).toBeNull();
    expect(safeNextPath('/\\evil.example')).toBeNull();
    expect(safeNextPath('/login')).toBeNull();
    expect(safeNextPath(null)).toBeNull();
  });
});
