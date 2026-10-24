import { useEffect, useRef, useState, type ReactNode } from 'react';

export type LandingRevealVariant =
  | 'heading'
  | 'signature'
  | 'practices'
  | 'workflow'
  | 'plans'
  | 'trust'
  | 'captures'
  | 'cta';

interface RevealOnScrollProps {
  ariaLabel?: string;
  children: ReactNode;
  className?: string;
  variant: LandingRevealVariant;
}

export function RevealOnScroll({ ariaLabel, children, className = '', variant }: RevealOnScrollProps) {
  const elementRef = useRef<HTMLDivElement>(null);
  const [isReady, setIsReady] = useState(false);
  const [isVisible, setIsVisible] = useState(false);

  useEffect(() => {
    const element = elementRef.current;
    if (!element) return;

    const prefersReducedMotion =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion || !('IntersectionObserver' in window)) {
      setIsVisible(true);
      return;
    }

    setIsReady(true);

    const observer = new IntersectionObserver(
      (entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        setIsVisible(true);
        observer.disconnect();
      },
      { rootMargin: '0px 0px -8% 0px', threshold: 0.16 },
    );

    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const revealState = isVisible ? 'is-visible' : isReady ? 'is-reveal-pending' : '';

  return (
    <div
      aria-label={ariaLabel}
      className={`landing-reveal ${revealState} ${className}`.trim()}
      data-reveal={variant}
      ref={elementRef}
    >
      {children}
    </div>
  );
}
