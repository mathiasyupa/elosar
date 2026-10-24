import { describe, expect, it } from 'vitest';
import { getRouteContext, isFocusRoute } from './routeContext';

describe('getRouteContext', () => {
  it.each([
    ['/dashboard', { eyebrow: 'Jornada clínica', title: 'Inicio' }],
    ['/agenda', { eyebrow: 'Operaciones', title: 'Agenda' }],
    ['/pacientes', { eyebrow: 'Operaciones', title: 'Pacientes' }],
    ['/pacientes/42/atencion', { eyebrow: 'Jornada clínica', title: 'Atención' }],
    ['/pacientes/42/consulta', { eyebrow: 'Jornada clínica', title: 'Atención' }],
    ['/pacientes/42/resumen', { eyebrow: 'Pacientes / Expediente', title: 'Resumen' }],
    ['/facturas', { eyebrow: 'Finanzas', title: 'Facturación' }],
  ])('maps %s to visible context', (pathname, expected) => {
    expect(getRouteContext(pathname)).toEqual(expected);
  });

  it('returns a neutral context for an unknown route', () => {
    expect(getRouteContext('/ruta-desconocida')).toEqual({
      eyebrow: 'Elosar',
      title: 'Área de trabajo',
    });
  });
});

// A2 · modo enfocado: mientras el médico atiende, el armazón de la app estorba.
// La decisión vive aquí, junto al resto de lo que el shell deduce de la ruta.
describe('isFocusRoute', () => {
  it('hides the shell while attending a patient', () => {
    expect(isFocusRoute('/pacientes/42/atencion')).toBe(true);
    expect(isFocusRoute('/pacientes/42/atencion/')).toBe(true);
  });

  it('also hides the global shell in the focused patient workspace', () => {
    expect(isFocusRoute('/pacientes/42/resumen')).toBe(true);
    expect(isFocusRoute('/pacientes/42/gineco')).toBe(true);
  });

  it('keeps the shell on every operational route, triage included', () => {
    // Enfermería se queda con el armazón: decisión de producto, no un olvido.
    expect(isFocusRoute('/triaje/1237')).toBe(false);
    expect(isFocusRoute('/pacientes')).toBe(false);
    expect(isFocusRoute('/dashboard')).toBe(false);
    expect(isFocusRoute('/')).toBe(false);
  });

  it('does not confuse a route that merely starts alike', () => {
    expect(isFocusRoute('/atenciones')).toBe(false);
  });
});
