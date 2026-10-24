import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { Input } from './Input';

describe('Input', () => {
  it('associates an error with its field for assistive technology', () => {
    const markup = renderToStaticMarkup(
      <Input id="email" label="Correo" error="Correo no válido" />,
    );

    expect(markup).toContain('aria-invalid="true"');
    expect(markup).toContain('aria-describedby="email-error"');
    expect(markup).toContain('id="email-error"');
  });

  it('renders a trailing action without changing the input semantics', () => {
    const markup = renderToStaticMarkup(
      <Input id="password" type="password" trailingAction={<button type="button">Ver</button>} />,
    );

    expect(markup).toContain('class="field-control has-trailing-action"');
    expect(markup).toContain('type="password"');
    expect(markup).toContain('<button type="button">Ver</button>');
  });

  it('marks a required labelled field without making callers add the marker by hand', () => {
    const markup = renderToStaticMarkup(
      <Input id="quantity" label="Cantidad a dispensar" required />,
    );

    expect(markup).toContain('Cantidad a dispensar');
    expect(markup).toContain('aria-hidden="true"');
    expect(markup).toContain('required=""');
  });
});
