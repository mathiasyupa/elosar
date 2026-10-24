interface Props {
  /** Rendered height in px. Width follows the logo's aspect ratio. */
  size?: number;
  className?: string;
}

/**
 * The Elosar logo (brand/logo — stacked 3D layers), shown bare on a transparent
 * background, never inside a box. It's a full-color mark, so it's never recolored.
 */
export function BrandGlyph({ size = 30, className }: Props) {
  return (
    <img
      src="/brand-logo.png"
      alt="Elosar"
      draggable={false}
      className={className}
      style={{ height: size, width: 'auto', display: 'block' }}
    />
  );
}
