import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const rootCss = readFileSync(new URL('../index.css', import.meta.url), 'utf8');
const themesCss = readFileSync(new URL('./themes.css', import.meta.url), 'utf8');
const presetsSource = readFileSync(new URL('../lib/themes/presets.ts', import.meta.url), 'utf8');
const paletteSource = readFileSync(new URL('../lib/theme.ts', import.meta.url), 'utf8');
const pickerSource = readFileSync(new URL('../features/settings/ThemePicker.tsx', import.meta.url), 'utf8');
const indexHtml = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');
const rootStart = rootCss.indexOf(':root {');
const rootBlock = rootCss.slice(rootStart, rootCss.indexOf('\n}', rootStart));

function presetBlock(preset: string, theme: 'light' | 'dark'): string {
  const selector = `[data-preset='${preset}'][data-theme='${theme}']`;
  const start = themesCss.indexOf(`${selector} {`);
  if (start === -1) return '';
  const end = themesCss.indexOf('\n}', start);
  return end === -1 ? '' : themesCss.slice(start, end);
}

function previewBlock(preset: string, mode: 'light' | 'dark'): string {
  const selector = `.theme-card[data-preview-preset='${preset}'][data-preview-mode='${mode}']`;
  const start = themesCss.indexOf(`${selector} {`);
  if (start === -1) return '';
  const end = themesCss.indexOf('\n}', start);
  return end === -1 ? '' : themesCss.slice(start, end);
}

describe('Contratos del selector de temas', () => {
  it('expone solamente Elosar, Slate Mono y Asencia Medical', () => {
    expect(presetsSource).toContain("export type ThemePresetId = 'serene' | 'slate' | 'asencia';");
    expect(presetsSource).toContain("name: 'Elosar'");
    expect(presetsSource).toContain("name: 'Slate Mono'");
    expect(presetsSource).toContain("name: 'Asencia Medical'");
    expect(presetsSource).not.toContain("id: 'reliant'");
    expect(presetsSource).not.toContain("id: 'terra'");
    expect(paletteSource).not.toContain('reliant:');
    expect(paletteSource).not.toContain('terra:');
    expect(paletteSource).toContain("serene: ['#0f766e'");
  });

  it('restaura Elosar si el navegador conserva un tema retirado', () => {
    expect(indexHtml).toContain("['serene', 'slate', 'asencia'].includes(savedPreset)");
  });

  it('preserva Elosar tal como estaba y adapta Slate y Asencia a su shell', () => {
    expect(rootBlock).toContain('--side-bg: #f7f9fd;');
    expect(rootBlock).toContain('--side-active-bg: #e9eeff;');
    expect(rootBlock).not.toContain('--side-active-rail:');
    expect(rootCss).toContain('background: var(--side-active-rail, var(--accent-600));');

    const elosarPreview = previewBlock('serene', 'light');
    expect(elosarPreview).toContain('--preview-side: #f7f9fd;');
    expect(elosarPreview).toContain('--preview-active: #e9eeff;');
    expect(elosarPreview).toContain('--preview-rail: #3c5fc2;');

    const slateLight = presetBlock('slate', 'light');
    expect(slateLight).toContain('--side-bg: #f6fafe;');
    expect(slateLight).toContain('--side-active-bg: #eaeef2;');
    expect(slateLight).toContain('--side-active-rail: #3f465c;');
    expect(slateLight).not.toContain('--side-bg: #101214;');

    const asenciaLight = presetBlock('asencia', 'light');
    expect(asenciaLight).toContain('--side-bg: #f5f6f7;');
    expect(asenciaLight).toContain('--side-active-bg: #cbe8e2;');
    expect(asenciaLight).toContain('--side-active-rail: #3a9a8a;');
    expect(asenciaLight).not.toContain('--side-bg: #142238;');

    expect(previewBlock('slate', 'light')).toContain('--preview-side: #f6fafe;');
    expect(previewBlock('asencia', 'light')).toContain('--preview-side: #f5f6f7;');

    for (const [preset, theme] of [
      ['slate', 'light'],
      ['slate', 'dark'],
      ['asencia', 'light'],
      ['asencia', 'dark'],
    ] as const) {
      const block = presetBlock(preset, theme);
      expect(block).toContain('--side-bg:');
      expect(block).toContain('--side-active-bg:');
      expect(block).toContain('--side-active-rail:');
      expect(block).toContain("--font: 'Inter'");
      expect(block).toContain("--font-display: 'Libre Caslon Text'");
    }

    expect(themesCss).not.toContain("[data-preset='slate'] .nav-label");
    expect(themesCss).not.toContain('--font-mono:');
    expect(themesCss).toContain('[data-preset] .app .page-head h1');
    expect(themesCss).toContain('font-family: var(--font-display);');
  });

  it('dibuja una miniatura estructural del shell sin inyectar colores desde JSX', () => {
    expect(pickerSource).toContain('data-preview-preset={preset.id}');
    expect(pickerSource).toContain('data-preview-mode={mode}');
    expect(pickerSource).toContain('theme-card-mini-active');
    expect(pickerSource).not.toContain('style=');
    expect(rootCss).toContain('.theme-card-mini-active::before');
    expect(themesCss).toContain(".theme-card[data-preview-preset='asencia'][data-preview-mode='dark']");
  });

  it('defines a distinct border for controls and regions in every active preset', () => {
    expect(rootBlock).toContain('--border-control:');

    for (const [preset, theme] of [
      ['slate', 'light'], ['slate', 'dark'],
      ['asencia', 'light'], ['asencia', 'dark'],
    ] as const) {
      expect(presetBlock(preset, theme)).toContain('--border-control:');
    }

    expect(rootCss).toContain('.card { background: var(--surface); border: 1px solid var(--border-control);');
    expect(rootCss).toContain('.field { width: 100%; border: 1px solid var(--border-control);');
  });

  /**
   * Un borde de componente y un separador que se repite no son el mismo trabajo.
   * El primero necesita 3:1 porque delimita algo con lo que se interactúa; el
   * segundo, repetido cien veces en una lista, sólo compite con el contenido.
   *
   * Se separaron tras usar la app en otro monitor: subir los tres tokens por
   * igual dejó Pacientes sobrecargado y la agenda como papel cuadriculado.
   */
  it('keeps repeating separators lighter than component borders', () => {
    expect(rootBlock).toContain('--divider:');

    for (const [preset, theme] of [
      ['slate', 'light'], ['slate', 'dark'],
      ['asencia', 'light'], ['asencia', 'dark'],
    ] as const) {
      expect(presetBlock(preset, theme)).toContain('--divider:');
    }

    // La rejilla de la agenda usa el separador, nunca el borde de componente.
    expect(rootCss).toContain('.cal-slot { display: block; width: 100%; border: none; border-top: 1px solid var(--divider);');
    expect(rootCss).toContain('.ag-cell { border-left: 1px solid var(--divider);');
  });

  /**
   * Las filas se siguen por franja alterna, no por una línea cada una. Y los
   * tres estados —franja, hover y seleccionado— tienen que ser distinguibles:
   * si el hover usara --accent-soft, pasar el ratón parecería seleccionar.
   */
  it('separates table rows with banding instead of a line per row', () => {
    expect(rootCss).toContain('.tbl td { padding: 14px 18px; font-size: 13.5px; }');
    expect(rootCss).not.toContain('.tbl td { padding: 14px 18px; border-bottom');
    expect(rootCss).toContain('.tbl tbody tr:nth-child(even) { background: var(--surface-2); }');
    expect(rootCss).toContain('.tbl tbody tr.selected {');
  });
});
