import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { LandingPage } from './LandingPage';
import { CAPTURE_SLOTS, LANDING_LINKS } from './landingData';

let consoleError: ReturnType<typeof vi.spyOn>;

beforeAll(() => {
  consoleError = vi.spyOn(console, 'error').mockImplementation((message, ...args) => {
    if (typeof message === 'string' && message.includes('useLayoutEffect does nothing on the server')) return;
    throw new Error([message, ...args].join(' '));
  });
});

afterAll(() => consoleError.mockRestore());

function renderLanding() {
  return renderToStaticMarkup(
    <MemoryRouter>
      <LandingPage />
    </MemoryRouter>,
  );
}

describe('LandingPage', () => {
  it('leads with the freedom to use personal and clinic workspaces from one account', () => {
    const markup = renderLanding();

    expect(markup).toContain('Una cuenta. Tu consultorio y tu clínica.');
    expect(markup).toContain('cambia de lugar de trabajo cuando lo necesites');
    expect(markup).toContain('Cambias de espacio sin cambiar de cuenta');
    expect(markup).not.toContain('La operación médica, organizada de punta a punta.');
    expect(markup).not.toContain('Demostración del producto');
  });

  it('keeps every short navigation destination directly reachable', () => {
    const markup = renderLanding();

    expect(LANDING_LINKS.map(([label]) => label)).toEqual([
      'Espacios',
      'Cómo funciona',
      'Planes',
      'Preguntas',
    ]);

    for (const [, href] of LANDING_LINKS) {
      const sectionId = href.replace('/#', '');
      expect(markup).toContain(`href="${href}"`);
      expect(markup.match(new RegExp(`id="${sectionId}"`, 'g'))).toHaveLength(1);
    }
  });

  it('uses the real account creation route without implying checkout', () => {
    const markup = renderLanding();

    expect(markup).toContain('Crear mi espacio');
    expect(markup).toContain('Empezar con este espacio');
    expect(markup).toContain('href="/crear-cuenta"');
    expect(markup).toContain('30 días de prueba');
    expect(markup).toContain('sin tarjeta');
    expect(markup).not.toMatch(/Pagar|Comprar|Suscribirme|Checkout/);
  });

  it('states the verified commercial model as two choices and one combined option', () => {
    const markup = renderLanding();

    expect(markup).toContain('USD 15');
    expect(markup).toContain('USD 21');
    expect(markup).toContain('USD 30');
    expect(markup).toContain('Incluye 2 médicos que atienden');
    expect(markup).toContain('Cada médico adicional suma USD 7 al mes');
    expect(markup).toContain('Solo se cuentan los médicos que atienden');
    expect(markup).toContain('Recepción, Caja, Enfermería y Administración');
    expect(markup).toContain('sin costo adicional');
  });

  it('closes the page with real product captures, not reserved placeholders', () => {
    const markup = renderLanding();
    const captureIndex = markup.indexOf('El producto, por dentro.');
    const plansIndex = markup.indexOf('Elige el espacio que corresponde a tu forma de trabajar.');

    expect(captureIndex).toBeGreaterThan(plansIndex);
    expect(markup).toContain('Agenda');
    expect(markup).toContain('Consulta');
    expect(markup).toContain('Receta');
    expect(markup).toContain('Documento clínico');

    // Cada hueco muestra una imagen real, y ninguna se queda sin `alt`: son
    // públicas, y sin descripción no las lee nadie con lector de pantalla.
    expect(CAPTURE_SLOTS).toHaveLength(4);
    for (const slot of CAPTURE_SLOTS) {
      expect(markup).toContain(`src="${slot.src}"`);
      expect(slot.alt.length).toBeGreaterThan(20);
      expect(markup).toContain(`alt="${slot.alt}"`);
    }

    // El marco reservado ya no existe, y el texto de la sección no puede volver
    // a prometer las capturas en futuro.
    expect(markup).not.toContain('Captura con datos de demostración');
    expect(markup).not.toContain('queda preparado para incorporar');
  });

  it('gives every important story beat its own scroll-reveal group', () => {
    const markup = renderLanding();

    expect(markup.match(/data-reveal="heading"/g)).toHaveLength(6);
    expect(markup).toContain('data-reveal="signature"');
    expect(markup).toContain('data-reveal="practices"');
    expect(markup).toContain('data-reveal="workflow"');
    expect(markup).toContain('data-reveal="plans"');
    expect(markup).toContain('data-reveal="trust"');
    expect(markup).toContain('data-reveal="captures"');
    expect(markup).toContain('data-reveal="cta"');
  });

  it('describes the functional SRI invoice flow without an institutional claim', () => {
    const markup = renderLanding();

    expect(markup).toContain('¿Cómo funciona la facturación electrónica con el SRI?');
    expect(markup).toContain('genera el comprobante electrónico y el XML');
    expect(markup).toContain('firma con el certificado .p12 configurado');
    expect(markup).toContain('envía al SRI');
    expect(markup).toContain('conserva la autorización');
    expect(markup).toContain('RIDE');
    expect(markup).toContain('requiere configuración fiscal y certificado');
    expect(markup).toContain('no implica aval, certificación, convenio ni afiliación con el SRI');
  });

  it('describes MSP-oriented clinical PDFs without overstating compliance', () => {
    const markup = renderLanding();

    expect(markup).toContain('¿Cómo apoyan las recetas y órdenes los requisitos del MSP?');
    expect(markup).toContain('Recetas y órdenes PDF con estructura pensada para el formato MSP');
    expect(markup).toContain('el profesional y el establecimiento completan y revisan los datos exigibles antes de emitirlas');
    expect(markup).toContain('no implica aval, certificación, convenio ni afiliación con el MSP');
  });
});
