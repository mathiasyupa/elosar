// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useOpenPatient } from './openPatient';

afterEach(() => vi.restoreAllMocks());

function setup(at: string) {
  const wrapper = ({ children }: { children: ReactNode }) => <MemoryRouter initialEntries={[at]}>{children}</MemoryRouter>;
  return renderHook(() => ({ open: useOpenPatient(), location: useLocation() }), { wrapper });
}

describe('useOpenPatient', () => {
  it('opens the expediente in its own tab from Inicio, Agenda or Pacientes', () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({ focus: vi.fn() } as unknown as Window);
    const { result } = setup('/dashboard');

    result.current.open(7, 'atencion?appointment=3');

    expect(open).toHaveBeenCalledWith('/pacientes/7/atencion?appointment=3', 'patient-workspace-7');
    expect(result.current.location.pathname).toBe('/dashboard');
  });

  it('stays in the same tab inside that patient\'s expediente', () => {
    const open = vi.spyOn(window, 'open');
    const { result, rerender } = setup('/pacientes/7/historial');

    result.current.open(7, 'atencion');
    rerender();

    expect(open).not.toHaveBeenCalled();
    expect(result.current.location.pathname).toBe('/pacientes/7/atencion');
  });
});
