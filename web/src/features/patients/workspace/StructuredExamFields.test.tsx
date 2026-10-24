// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StructuredExamFields } from './StructuredExamFields';
import { CLINICAL_TEMPLATES, currentTemplateVersionFor, fieldsForTemplate } from './clinicalTemplates';
import type { ConsultationType } from '@/api/types';

afterEach(cleanup);

/** El examen por segmentos se ve como un acordeón: cada grupo hay que abrirlo
 *  (clic en su <summary>) antes de que su campo sea interactuable — igual que
 *  haría el médico en la pantalla real. */
async function openAccordionGroup(title: string) {
  const user = userEvent.setup();
  // `.accordion-title` desambigua del texto del campo: en la plantilla `general`
  // el título del grupo coincide literalmente con la etiqueta de su único campo.
  await user.click(screen.getByText(title, { selector: '.accordion-title' }));
}

describe('StructuredExamFields', () => {
  // A general consultation is the most common one, and it used to be the single
  // type with no structured exploration at all — it rendered nothing.
  it('gives a general consultation its head-to-toe segments, one per accordion item', async () => {
    render(<StructuredExamFields type="general" value={{}} onChange={vi.fn()} />);

    for (const label of ['Cabeza', 'Cuello', 'Tórax', 'Abdomen']) {
      await openAccordionGroup(label);
      expect(screen.getByLabelText(label)).toBeTruthy();
    }
  });

  it('keeps the specialty templates addressable', async () => {
    render(<StructuredExamFields type="gynecological" value={{}} onChange={vi.fn()} />);

    await openAccordionGroup('Ginecológico');
    expect(screen.getByLabelText('Gestas')).toBeTruthy();
  });

  /**
   * Reemplaza a un test que fijaba lo contrario — que una consulta ginecológica
   * NO mostraba «Cabeza». Era una decisión real y se cambió a propósito en la v2:
   * elegir «Ginecológica» o «Control prenatal» hacía desaparecer el examen por
   * segmentos, así que la consulta más frecuente en gineco-obstetricia perdía
   * cabeza, cuello, tórax y abdomen. Una embarazada también tiene tórax.
   */
  it.each(['gynecological', 'prenatal'] as const)(
    'compone los segmentos corporales con lo específico en %s',
    async (type) => {
      render(<StructuredExamFields type={type} value={{}} onChange={vi.fn()} />);

      await openAccordionGroup('Exploración física por segmentos');
      expect(screen.getByLabelText('Tórax')).toBeTruthy();
      expect(screen.getByLabelText('Abdomen')).toBeTruthy();

      const ownGroup = type === 'prenatal' ? 'Control prenatal' : 'Ginecológico';
      const own = type === 'prenatal' ? 'Altura uterina (cm)' : 'Examen pélvico';
      await openAccordionGroup(ownGroup);
      expect(screen.getByLabelText(own)).toBeTruthy();
    },
  );

  it('titula los bloques de una plantilla compuesta', () => {
    render(<StructuredExamFields type="prenatal" value={{}} onChange={vi.fn()} />);

    expect(screen.getByText('Exploración física por segmentos')).toBeTruthy();
    expect(screen.getByText('Control prenatal')).toBeTruthy();
  });

  it('no titula nada cuando la plantilla es una sola lista', () => {
    render(<StructuredExamFields type="general" value={{}} onChange={vi.fn()} />);

    expect(screen.queryByText('Exploración física por segmentos')).toBeNull();
  });

  it('reports each segment under its own key so nothing overwrites a sibling', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<StructuredExamFields type="general" value={{ cuello: 'sin adenopatías' }} onChange={onChange} />);

    await openAccordionGroup('Tórax');
    await user.type(screen.getByLabelText('Tórax'), 'x');

    expect(onChange).toHaveBeenCalledWith({ cuello: 'sin adenopatías', torax: 'x' });
  });

  it('marca con un indicador los segmentos que ya tienen contenido', () => {
    render(<StructuredExamFields type="general" value={{ cuello: 'sin adenopatías' }} onChange={vi.fn()} />);

    const cuelloSummary = screen.getByText('Cuello', { selector: '.accordion-title' }).closest('summary');
    const cabezaSummary = screen.getByText('Cabeza', { selector: '.accordion-title' }).closest('summary');
    expect(cuelloSummary?.querySelector('.accordion-dot')).toBeTruthy();
    expect(cabezaSummary?.querySelector('.accordion-dot')).toBeNull();
  });

  it('has a template for every consultation type', () => {
    const types = Object.keys(CLINICAL_TEMPLATES[1]) as ConsultationType[];

    for (const type of types) {
      expect(fieldsForTemplate(type).length, `${type} has no fields`).toBeGreaterThan(0);
    }
  });

  /** Dos secciones no pueden pisarse una clave: el mapa guardado es plano. */
  it('no repite una clave dentro de una plantilla', () => {
    for (const [version, byType] of Object.entries(CLINICAL_TEMPLATES)) {
      for (const [type, fields] of Object.entries(byType)) {
        const keys = fields.map((f) => f.key);
        expect(new Set(keys).size, `${type} v${version} repite una clave`).toBe(keys.length);
      }
    }
  });

  /**
   * El servidor sella la versión y valida la exploración contra ella. Si el
   * cliente pinta una plantilla de otra versión, el médico se come un 422 en
   * mitad de la consulta — así que esto fija cuáles van en v2.
   */
  it('pide la misma versión que sella el servidor', () => {
    expect(currentTemplateVersionFor('gynecological')).toBe(2);
    expect(currentTemplateVersionFor('prenatal')).toBe(3);
    expect(currentTemplateVersionFor('pediatric')).toBe(2);
    expect(currentTemplateVersionFor('general')).toBe(1);
    expect(currentTemplateVersionFor('preventive')).toBe(1);
  });

  /**
   * Cuatro tamizajes de sí/no que antes solo cabían en "Observaciones" — ver
   * la adenda de ADR-009. Se registran por control, no fijos por trimestre.
   */
  it('muestra los tamizajes prenatales v3 como sí/no, sin forzar una respuesta', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<StructuredExamFields type="prenatal" value={{}} onChange={onChange} />);

    expect(screen.getByText('Tamizajes y consejería de este control')).toBeTruthy();
    await openAccordionGroup('Tamizajes y consejería de este control');

    const select = screen.getByLabelText('Tamizaje de violencia intrafamiliar') as HTMLSelectElement;
    expect(select.tagName).toBe('SELECT');
    expect(select.value).toBe('');

    await user.selectOptions(select, 'no');

    expect(onChange).toHaveBeenCalledWith({ tamizaje_violencia_intrafamiliar: 'no' });
  });
});
