import { readPreset, useThemeStore, type ThemeMode } from '@/store/theme';
import type { ThemePresetId } from '@/lib/themes/presets';

export type { ThemeMode };

const PALETTES: Record<ThemePresetId, string[]> = {
  serene: ['#0f766e', '#3b6ba5', '#6d5fa8', '#a06d1c', '#2f8a5e', '#ad5a7d', '#5c7d8a'],
  slate: ['#565e74', '#505f76', '#3f465c', '#131b2e', '#54647a', '#76777d', '#171c1f'],
  asencia: ['#45B4A2', '#2C4165', '#1B2C49', '#5fc4b4', '#6B7280', '#3a9a8a', '#24417C'],
};

const GRADIENTS: Record<ThemePresetId, string[]> = {
  serene: [
    'linear-gradient(135deg,#2ba192,#0f766e)',
    'linear-gradient(135deg,#5f8fc4,#3b6ba5)',
    'linear-gradient(135deg,#8f82c9,#6d5fa8)',
    'linear-gradient(135deg,#c99a4b,#a06d1c)',
    'linear-gradient(135deg,#5fae85,#2f8a5e)',
    'linear-gradient(135deg,#c789a3,#ad5a7d)',
  ],
  slate: [
    'linear-gradient(135deg,#565e74,#131b2e)',
    'linear-gradient(135deg,#505f76,#3f465c)',
    'linear-gradient(135deg,#76777d,#45464d)',
    'linear-gradient(135deg,#131b2e,#000000)',
    'linear-gradient(135deg,#7c839b,#565e74)',
    'linear-gradient(135deg,#bec6e0,#565e74)',
  ],
  asencia: [
    'linear-gradient(135deg,#5fc4b4,#45B4A2)',
    'linear-gradient(135deg,#2C4165,#1B2C49)',
    'linear-gradient(135deg,#45B4A2,#2C4165)',
    'linear-gradient(135deg,#CBE8E2,#45B4A2)',
    'linear-gradient(135deg,#24417C,#1B2C49)',
    'linear-gradient(135deg,#FFE8DA,#45B4A2)',
  ],
};

/** Shared theme state — topbar toggle and settings picker use the same store. */
export function useTheme() {
  const preset = useThemeStore((s) => s.preset);
  const mode = useThemeStore((s) => s.mode);
  const setPreset = useThemeStore((s) => s.setPreset);
  const setMode = useThemeStore((s) => s.setMode);
  const toggleMode = useThemeStore((s) => s.toggleMode);

  return { preset, mode, setPreset, setMode, toggleMode, theme: mode, toggle: toggleMode };
}

/** Stable avatar background color for a numeric seed (e.g. a patient id). */
export function avatarColor(seed: number | string, preset: ThemePresetId = readPreset()): string {
  const palette = PALETTES[preset] ?? PALETTES.serene;
  const n = typeof seed === 'number' ? seed : seed.length;
  return palette[Math.abs(n) % palette.length];
}

/** Stable gradient cover for a workspace/clinic card. */
export function workspaceGradient(seed: number | string, preset: ThemePresetId = readPreset()): string {
  const gradients = GRADIENTS[preset] ?? GRADIENTS.serene;
  const n = typeof seed === 'number' ? seed : seed.length;
  return gradients[Math.abs(n) % gradients.length];
}

/** Initials from a person's name, e.g. "Gregory House" → "GH". */
export function initials(name: string | null | undefined): string {
  if (!name) return '—';
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('');
}
