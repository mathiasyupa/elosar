import { create } from 'zustand';
import { isThemePresetId, type ThemePresetId } from '@/lib/themes/presets';

export type ThemeMode = 'light' | 'dark';

const PRESET_KEY = 'medikal-preset';
const MODE_KEY = 'medikal-theme';

/** Elosar en claro: lo que ve quien entra por primera vez. */
const DEFAULT_PRESET: ThemePresetId = 'serene';
const DEFAULT_MODE: ThemeMode = 'light';

export function readPreset(): ThemePresetId {
  try {
    const v = localStorage.getItem(PRESET_KEY);
    if (isThemePresetId(v)) return v;
  } catch {
    /* private mode */
  }
  return DEFAULT_PRESET;
}

/**
 * Solo se respeta lo que el usuario eligió aquí dentro. El modo oscuro del
 * sistema operativo NO decide: hacía que la primera impresión del producto
 * dependiera de la configuración del computador de cada quien.
 */
export function readMode(): ThemeMode {
  try {
    const v = localStorage.getItem(MODE_KEY);
    if (v === 'dark' || v === 'light') return v;
  } catch {
    /* private mode */
  }

  // Sin preferencia guardada: lo que el script anti-FOUC ya pintó (claro).
  return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : DEFAULT_MODE;
}

function applyToDom(preset: ThemePresetId, mode: ThemeMode) {
  document.documentElement.setAttribute('data-preset', preset);
  document.documentElement.setAttribute('data-theme', mode);
  try {
    localStorage.setItem(PRESET_KEY, preset);
    localStorage.setItem(MODE_KEY, mode);
  } catch {
    /* localStorage may be unavailable */
  }
}

interface ThemeState {
  preset: ThemePresetId;
  mode: ThemeMode;
  setPreset: (preset: ThemePresetId) => void;
  setMode: (mode: ThemeMode) => void;
  toggleMode: () => void;
}

/** Single source of truth for theme preset + mode (shared by topbar and settings). */
export const useThemeStore = create<ThemeState>((set, get) => ({
  preset: readPreset(),
  mode: readMode(),

  setPreset: (preset) => {
    applyToDom(preset, get().mode);
    set({ preset });
  },

  setMode: (mode) => {
    applyToDom(get().preset, mode);
    set({ mode });
  },

  toggleMode: () => {
    const next: ThemeMode = get().mode === 'dark' ? 'light' : 'dark';
    applyToDom(get().preset, next);
    set({ mode: next });
  },
}));

// Sync DOM once on module load (covers HMR / store re-init after FOUC script).
applyToDom(useThemeStore.getState().preset, useThemeStore.getState().mode);
