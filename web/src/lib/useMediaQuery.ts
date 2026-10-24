import { useEffect, useState } from 'react';

/** Reactive CSS media query. Re-renders when the match changes (rotation, resize). */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(
    () => typeof window !== 'undefined' && window.matchMedia(query).matches,
  );

  useEffect(() => {
    const mql = window.matchMedia(query);
    const onChange = (e: MediaQueryListEvent) => setMatches(e.matches);
    setMatches(mql.matches);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, [query]);

  return matches;
}

/**
 * Phone-sized viewport. Kept in sync with the 720px breakpoint the stylesheet
 * uses for the mobile table/card treatment, so layout and behaviour agree.
 */
export function useIsMobile(): boolean {
  return useMediaQuery('(max-width: 720px)');
}
