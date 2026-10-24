import type { CSSProperties, ReactNode } from 'react';

interface CardProps {
  children: ReactNode;
  className?: string;
  style?: CSSProperties;
  /** Adds inner padding. Set false for tables/lists that manage their own padding. */
  padded?: boolean;
}

export function Card({ children, className = '', style, padded = true }: CardProps) {
  return (
    <div className={`card ${padded ? 'card-pad' : ''} ${className}`.trim()} style={style}>
      {children}
    </div>
  );
}
