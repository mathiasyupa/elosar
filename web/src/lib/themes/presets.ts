export type ThemePresetId = 'serene' | 'slate' | 'asencia';

export const VALID_PRESET_IDS: ThemePresetId[] = ['serene', 'slate', 'asencia'];

export function isThemePresetId(value: string | null | undefined): value is ThemePresetId {
  return VALID_PRESET_IDS.includes(value as ThemePresetId);
}

export interface ThemePresetMeta {
  id: ThemePresetId;
  name: string;
  tagline: string;
}

export const THEME_PRESETS: ThemePresetMeta[] = [
  {
    id: 'serene',
    name: 'Elosar',
    tagline: 'Teal clínico y sidebar claro — el tema del sistema',
  },
  {
    id: 'slate',
    name: 'Slate Mono',
    tagline: 'Grafito y gris niebla con interfaz clara',
  },
  {
    id: 'asencia',
    name: 'Asencia Medical',
    tagline: 'Teal médico y navy profundo con interfaz clara',
  },
];
