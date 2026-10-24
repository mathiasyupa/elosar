import type { SVGProps } from 'react';

// Line icons adapted from design/propuesta-visual-2.html. Only the subset the
// real app uses is included — no invented features.
const PATHS: Record<string, string> = {
  cal: '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9h18M8 2.5v4M16 2.5v4"/>',
  users:
    '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M16 5.2A3 3 0 0 1 16 11M21 20c0-2.4-1.3-4-3.5-4.6"/>',
  invoice: '<path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
  moon: '<path d="M21 13A8.5 8.5 0 1 1 11 3a6.5 6.5 0 0 0 10 10z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
  building: '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/>',
  chevron: '<path d="m7 9 5 5 5-5"/>',
  check: '<path d="m5 12 5 5L20 6"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  pill: '<rect x="3" y="9" width="18" height="6" rx="3" transform="rotate(45 12 12)"/><path d="M9 9 15 15" transform="rotate(45 12 12)"/>',
  steth: '<path d="M5 3v5a4 4 0 0 0 8 0V3"/><path d="M9 16v1a5 5 0 0 0 10 0v-2"/><circle cx="19" cy="11" r="2.2"/>',
  brand: '<path d="M3 12h4l2 5 4-12 2 7h6"/>',
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
  file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M8.5 13h7M8.5 16.5h7"/>',
  folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  card: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/>',
  shield: '<path d="M12 3 5 6v5c0 4.4 3 7.8 7 9 4-1.2 7-4.6 7-9V6z"/><path d="m9.5 12 2 2 3.5-3.6"/>',
  chart: '<path d="M4 20V4M20 20H4"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/>',
  cog: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 1.1-2.7H3a2 2 0 0 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
  bell: '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/>',
  money: '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5a2.5 2 0 0 1 5 0c0 1.5-5 1-5 2.5a2.5 2 0 0 0 5 0"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  eye: '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/>',
  eyeOff: '<path d="m3 3 18 18"/><path d="M10.6 6.2A10.2 10.2 0 0 1 12 6c6 0 9.5 6 9.5 6a17.7 17.7 0 0 1-3.35 3.9M6.1 6.1A17.6 17.6 0 0 0 2.5 12s3.5 6 9.5 6c1.1 0 2.1-.2 3-.55"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  trash: '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
  // Menú de desbordamiento de una fila. Tres puntos, no un icono con nombre:
  // la acción que abre depende del contexto.
  dots: '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>',
  download: '<path d="M12 3v12M7 11l5 5 5-5M5 21h14"/>',
  save: '<path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V3z"/><path d="M8 3v5h7V3M8 21v-7h8v7"/>',
  upload: '<path d="M12 21V9M7 13l5-5 5 5M5 3h14"/>',
  x: '<path d="M6 6l12 12M18 6 6 18"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  gift: '<rect x="3" y="9" width="18" height="11" rx="1.5"/><path d="M3 13h18M12 9v11"/><path d="M12 9C9.5 9 7.5 8 7.5 6.2A2.2 2.2 0 0 1 12 6a2.2 2.2 0 0 1 4.5.2C16.5 8 14.5 9 12 9z"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
  whatsapp: '<path d="M20 11.5a8 8 0 0 1-11.8 7L4 19.5l1.1-4A8 8 0 1 1 20 11.5z"/><path d="M9 8.5c.2 1.6 1.2 3.4 2.4 4.5 1.2 1.1 2.4 1.6 3.4 1.7.4 0 .7-.2.9-.6l.3-.7a.6.6 0 0 0-.3-.8l-1.4-.6a.6.6 0 0 0-.6.1l-.4.4c-.7-.3-1.5-1-1.9-1.8l.4-.4a.6.6 0 0 0 .1-.6l-.6-1.4a.6.6 0 0 0-.8-.3l-.7.3c-.4.2-.6.6-.5 1z"/>',
  // Jeringa — carnet de vacunación
  syringe: '<path d="m18 2 4 4M17 7l3-3M19 9 8.7 19.3a2.4 2.4 0 0 1-3.4 0l-.6-.6a2.4 2.4 0 0 1 0-3.4L15 5M9 11l4 4M5 19l-3 3M14 4l6 6"/>',
  // Matraz de laboratorio — solicitud de exámenes / imágenes
  flask: '<path d="M9 3h6M10 3v6.5L5 18a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-8.5V3"/><path d="M7.5 15h9"/>',
  // Burbuja de chat — asistente de IA
  chat: '<path d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-4.5 4v-4H6a2 2 0 0 1-2-2z"/>',
  phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
  pin: '<path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
  // Triángulo de advertencia — estados de error
  alert: '<path d="M10.3 3.8 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  // Micrófono — "Escuchar con Elo"
  mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7"/>',
  // Flechas circulares — reintentar / recargar
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
};

interface IconProps extends Omit<SVGProps<SVGSVGElement>, 'name'> {
  name: keyof typeof PATHS | string;
  size?: number;
}

export function Icon({ name, size = 20, ...props }: IconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.9}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      dangerouslySetInnerHTML={{ __html: PATHS[name] ?? '' }}
      {...props}
    />
  );
}
