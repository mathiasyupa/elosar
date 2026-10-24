import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { PregnancyChip } from './PregnancyChip';
import type { Pregnancy } from '@/api/types';

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
      days: 228,
      weeks: 32,
      remainder_days: 4,
      label: '32+4',
      trimester: 3,
      is_term: false,
      is_post_term: false,
      is_valid: true,
    },
    fetus_count: 1,
    ...over,
  };
}

describe('PregnancyChip', () => {
  it('muestra semanas, días y FPP', () => {
    const html = renderToStaticMarkup(<PregnancyChip pregnancy={pregnancy()} />);

    expect(html).toContain('32 s 4 d');
    expect(html).toContain('FPP');
    expect(html).toContain('por FUM');
  });

  it('dice de dónde salió la datación cuando manda la ecografía', () => {
    const html = renderToStaticMarkup(
      <PregnancyChip
        pregnancy={pregnancy({
          dating: {
            edd_by_lmp: '2026-10-08',
            edd_by_ultrasound: '2026-09-27',
            discrepancy_days: 11,
            source_used: 'ultrasound',
            missing: [],
          },
        })}
      />,
    );

    expect(html).toContain('por eco');
  });

  /** Un embarazo sin FUM ni ecografía no es "0 semanas". Decir que está sin datar
   *  es más útil que esconder el chip, porque es justo lo que hay que corregir. */
  it('dice que el embarazo está sin datar en vez de inventar cero semanas', () => {
    const html = renderToStaticMarkup(
      <PregnancyChip
        pregnancy={pregnancy({
          lmp_date: null,
          edd_date: null,
          gestational_age: {
            days: null,
            weeks: null,
            remainder_days: null,
            label: null,
            trimester: null,
            is_term: false,
            is_post_term: false,
            is_valid: false,
          },
        })}
      />,
    );

    expect(html).toContain('Embarazo sin datar');
    expect(html).not.toContain('0 s');
  });

  it('cambia de tono a término y pasadas las 42 semanas', () => {
    const term = renderToStaticMarkup(
      <PregnancyChip
        pregnancy={pregnancy({
          gestational_age: { ...pregnancy().gestational_age, is_term: true },
        })}
      />,
    );
    const post = renderToStaticMarkup(
      <PregnancyChip
        pregnancy={pregnancy({
          gestational_age: { ...pregnancy().gestational_age, is_term: true, is_post_term: true },
        })}
      />,
    );

    expect(term).toContain('preg-chip term');
    expect(post).toContain('preg-chip post-term');
  });

  /** Un embarazo cerrado no es un aviso: no debe seguir ocupando la banda. */
  it('no se pinta para un embarazo que ya terminó', () => {
    const html = renderToStaticMarkup(
      <PregnancyChip pregnancy={pregnancy({ status: 'completed' })} />,
    );

    expect(html).toBe('');
  });
});
