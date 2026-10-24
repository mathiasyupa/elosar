// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PregnancyCard } from './PregnancyCard';
import type { Pregnancy, PrenatalScreening } from '@/api/types';

vi.mock('@/api/obstetrics', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/obstetrics')>()),
  fetchScreenings: vi.fn(),
  saveScreening: vi.fn(),
  fetchPregnancyTimeline: vi.fn().mockResolvedValue([]),
}));

const { fetchScreenings } = await import('@/api/obstetrics');

afterEach(cleanup);

function screening(over: Partial<PrenatalScreening> = {}): PrenatalScreening {
  return {
    code: 'hemoglobin',
    label: 'Hemoglobina y hematocrito',
    when: 'Primer control de cada embarazo',
    universal: true,
    result: null,
    result_at: null,
    notes: null,
    recorded: false,
    ...over,
  };
}

function pregnancy(over: Partial<Pregnancy> = {}): Pregnancy {
  return {
    id: 1,
    patient_id: 1,
    status: 'active',
    status_label: 'En curso',
    lmp_date: '2026-01-01',
    dating_source: 'lmp',
    edd_date: '2026-10-08',
    edd_protocol: 'NAEGELE-280',
    dating: {
      edd_by_lmp: '2026-10-08',
      edd_by_ultrasound: null,
      discrepancy_days: null,
      source_used: 'lmp',
      missing: ['ultrasound'],
    },
    gestational_age: {
      days: 228, weeks: 32, remainder_days: 4, label: '32+4',
      trimester: 3, is_term: false, is_post_term: false, is_valid: true,
    },
    fetus_count: 1,
    ...over,
  };
}

function renderCard(p: Pregnancy) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <PregnancyCard pregnancy={p} canEdit onEdit={vi.fn()} onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe('PregnancyCard', () => {
  /**
   * El carnet sirve para ver lo que FALTA. Una lista de solo lo hecho no puede
   * mostrarlo, así que vuelven todos los exámenes del calendario.
   */
  it('lista también los exámenes que nadie ha hecho', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([
      screening({ code: 'hemoglobin', recorded: true, result: '11.2' }),
      screening({ code: 'vdrl', label: 'VDRL (sífilis)', recorded: false }),
    ]);

    renderCard(pregnancy());

    await waitFor(() => expect(screen.getByText('VDRL (sífilis)')).toBeTruthy());
    expect(screen.getByText('1 de 2')).toBeTruthy();
  });

  /**
   * La guía pide algunos exámenes solo ante un factor de riesgo. Sin decirlo, una
   * casilla vacía se lee como un olvido — y es justo donde el competidor se
   * separa de la norma.
   */
  it('marca los que la norma pide solo ante un factor de riesgo', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([
      screening({ code: 'hemoglobin', universal: true }),
      screening({ code: 'group_b_strep', label: 'Estreptococo grupo B', universal: false }),
    ]);

    renderCard(pregnancy());

    await waitFor(() => expect(screen.getByText('Estreptococo grupo B')).toBeTruthy());
    // Una sola marca: la del que no es universal.
    expect(screen.getAllByText('según riesgo')).toHaveLength(1);
  });

  /**
   * El producto no redata solo —la norma no publica umbral—, así que cuando las
   * dos dataciones se separan, lo dice y deja la decisión al médico.
   */
  it('avisa cuando la FUM y la ecografía no coinciden', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([]);

    renderCard(
      pregnancy({
        dating: {
          edd_by_lmp: '2026-10-08',
          edd_by_ultrasound: '2026-09-27',
          discrepancy_days: 11,
          source_used: 'lmp',
          missing: [],
        },
      }),
    );

    await waitFor(() =>
      expect(screen.getByText('La FUM y la ecografía no coinciden')).toBeTruthy(),
    );
    expect(screen.getByText(/11 días/)).toBeTruthy();
  });

  it('no avisa por una diferencia de pocos días', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([]);

    renderCard(
      pregnancy({
        dating: {
          edd_by_lmp: '2026-10-08',
          edd_by_ultrasound: '2026-10-05',
          discrepancy_days: 3,
          source_used: 'lmp',
          missing: [],
        },
      }),
    );

    await waitFor(() => expect(screen.getByText('FUM')).toBeTruthy());
    expect(screen.queryByText('La FUM y la ecografía no coinciden')).toBeNull();
  });

  /** Confirmado con el médico: 39 semanas, una menos que la FPP. Es una fecha de
   *  calendario, no un criterio clínico — por eso el rótulo dice "39 sem." en
   *  vez de sonar a una indicación de cesárea. */
  it('muestra la fecha de cesárea electiva cuando el servidor la manda', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([]);

    renderCard(pregnancy({ elective_cesarean_date: '2026-10-01' }));

    await waitFor(() => expect(screen.getByText('Cesárea electiva (39 sem.)')).toBeTruthy());
  });

  it('no muestra la fila de cesárea sin la fecha', async () => {
    vi.mocked(fetchScreenings).mockResolvedValue([]);

    renderCard(pregnancy({ elective_cesarean_date: null }));

    await waitFor(() => expect(screen.getByText('FUM')).toBeTruthy());
    expect(screen.queryByText('Cesárea electiva (39 sem.)')).toBeNull();
  });
});
