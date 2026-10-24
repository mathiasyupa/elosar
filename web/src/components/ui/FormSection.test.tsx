import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { FormSection } from './FormSection';

describe('FormSection', () => {
  it('groups fields under a visible heading and optional description', () => {
    const markup = renderToStaticMarkup(
      <FormSection title="Identidad" description="Datos legales del paciente">
        <input name="name" />
      </FormSection>,
    );

    expect(markup).toContain('Identidad');
    expect(markup).toContain('Datos legales del paciente');
    expect(markup).toContain('form-section');
  });
});
