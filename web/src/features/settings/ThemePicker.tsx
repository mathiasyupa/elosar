import { Icon } from '@/components/Icon';
import { useTheme } from '@/lib/theme';
import { THEME_PRESETS } from '@/lib/themes/presets';

export function ThemePicker() {
  const { preset, mode, setPreset, setMode, toggleMode } = useTheme();

  return (
    <div className="theme-picker">
      <div className="set-row theme-mode-row">
        <div className="lbl">
          <b>Modo claro / oscuro</b>
          <span>Aplica al tema seleccionado</span>
        </div>
        <div className="set-ctrl">
          <div className="theme-mode-toggle">
            <button
              type="button"
              className={mode === 'light' ? 'active' : ''}
              onClick={() => setMode('light')}
              aria-pressed={mode === 'light'}
            >
              <Icon name="sun" size={15} /> Claro
            </button>
            <button
              type="button"
              className={mode === 'dark' ? 'active' : ''}
              onClick={() => setMode('dark')}
              aria-pressed={mode === 'dark'}
            >
              <Icon name="moon" size={15} /> Oscuro
            </button>
          </div>
        </div>
      </div>

      <p className="theme-picker-hint">Elige un estilo visual para probar en la aplicación</p>

      <div className="theme-grid">
        {THEME_PRESETS.map((p) => (
          <ThemeCard
            key={p.id}
            preset={p}
            mode={mode}
            selected={preset === p.id}
            onSelect={() => setPreset(p.id)}
          />
        ))}
      </div>

      <div className="theme-preview-strip">
        <span className="theme-preview-label">Vista previa en vivo</span>
        <div className="theme-preview-ui">
          <div className="theme-preview-sidebar" aria-hidden>
            <span className="theme-preview-brand" />
            <span className="theme-preview-nav-item" />
            <span className="theme-preview-nav-item active" />
            <span className="theme-preview-nav-item" />
          </div>
          <div className="theme-preview-main">
            <div className="theme-preview-topbar" />
            <div className="theme-preview-card">
              <span className="theme-preview-dot" />
              <span className="theme-preview-line" />
              <span className="theme-preview-line short" />
              <button type="button" className="theme-preview-btn" onClick={toggleMode}>
                Cambiar modo
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ThemeCard({
  preset,
  mode,
  selected,
  onSelect,
}: {
  preset: (typeof THEME_PRESETS)[number];
  mode: 'light' | 'dark';
  selected: boolean;
  onSelect: () => void;
}) {
  const { name, tagline } = preset;

  return (
    <button
      type="button"
      className={`theme-card ${selected ? 'selected' : ''}`}
      onClick={onSelect}
      aria-pressed={selected}
      data-preview-preset={preset.id}
      data-preview-mode={mode}
    >
      <div className="theme-card-preview" aria-hidden>
        <div className="theme-card-mini">
          <div className="theme-card-side">
            <span className="theme-card-mini-brand" />
            <span className="theme-card-mini-nav" />
            <span className="theme-card-mini-nav theme-card-mini-active" />
            <span className="theme-card-mini-nav" />
          </div>
          <div className="theme-card-content">
            <div className="theme-card-bar" />
            <div className="theme-card-block">
              <div className="theme-card-chip" />
              <div className="theme-card-lines">
                <i />
                <i />
              </div>
            </div>
          </div>
        </div>
        <div className="theme-card-swatches">
          <span className="theme-card-swatch-side" />
          <span className="theme-card-swatch-accent" />
          <span className="theme-card-swatch-surface" />
          <span className="theme-card-swatch-bg" />
        </div>
      </div>
      <div className="theme-card-meta">
        <b>{name}</b>
        <span>{tagline}</span>
      </div>
      {selected && (
        <span className="theme-card-check">
          <Icon name="check" size={14} />
        </span>
      )}
    </button>
  );
}
