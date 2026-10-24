import { useEffect, useRef, useState } from 'react';

/** Below this the chart scrolls sideways instead of shrinking (see .chart-scroll). */
const MIN_CHART_WIDTH = 660;

/**
 * Measures the chart container so the SVG viewBox can match its real pixel
 * width.
 *
 * A fixed viewBox is only ever 1:1 at one screen size: the container scales it
 * to fit, and everything — bar widths, axis labels, the whole height — scales
 * with it. On a 1514px content area a 920-wide viewBox is drawn at 1.65x, so
 * the chart grows to 369px tall with oversized text. Feeding the measured
 * width back in keeps the drawing 1:1 and the height constant at any monitor.
 */
export function useChartWidth(): [React.Ref<HTMLDivElement>, number] {
  const ref = useRef<HTMLDivElement | null>(null);
  const [width, setWidth] = useState(MIN_CHART_WIDTH);

  useEffect(() => {
    const element = ref.current;
    if (!element) return;

    const measure = () => setWidth(Math.max(MIN_CHART_WIDTH, Math.round(element.clientWidth)));
    measure();

    // Two independent triggers on purpose. ResizeObserver also catches layout
    // changes that don't resize the window (sidebar collapsing, a panel
    // opening); the window listener covers the common case even where the
    // observer is unavailable or throttled.
    window.addEventListener('resize', measure);
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null;
    observer?.observe(element);

    return () => {
      window.removeEventListener('resize', measure);
      observer?.disconnect();
    };
  }, []);

  return [ref, width];
}
