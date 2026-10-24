/* @vitest-environment jsdom */

import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RevealOnScroll } from './RevealOnScroll';

let reportIntersection: IntersectionObserverCallback;

class TestIntersectionObserver implements IntersectionObserver {
  readonly root = null;
  readonly rootMargin = '0px 0px -8% 0px';
  readonly thresholds = [0.16];

  constructor(callback: IntersectionObserverCallback) {
    reportIntersection = callback;
  }

  disconnect() {}
  observe() {}
  takeRecords() { return []; }
  unobserve() {}
}

function stubReducedMotion(matches: boolean) {
  vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({
    matches,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }));
}

describe('RevealOnScroll', () => {
  beforeEach(() => {
    stubReducedMotion(false);
    vi.stubGlobal('IntersectionObserver', TestIntersectionObserver);
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('reveals content once it enters the viewport and keeps it visible', () => {
    const { container } = render(
      <RevealOnScroll variant="heading">Contenido</RevealOnScroll>,
    );
    const reveal = container.querySelector('[data-reveal="heading"]');

    expect(reveal?.classList.contains('is-reveal-pending')).toBe(true);

    act(() => {
      reportIntersection([{ isIntersecting: true, target: reveal } as IntersectionObserverEntry], {} as IntersectionObserver);
    });

    expect(reveal?.classList.contains('is-visible')).toBe(true);

    act(() => {
      reportIntersection([{ isIntersecting: false, target: reveal } as IntersectionObserverEntry], {} as IntersectionObserver);
    });

    expect(reveal?.classList.contains('is-visible')).toBe(true);
  });

  it('shows the final state immediately when reduced motion is preferred', () => {
    stubReducedMotion(true);

    const { container } = render(
      <RevealOnScroll variant="signature">Contenido</RevealOnScroll>,
    );
    const reveal = container.querySelector('[data-reveal="signature"]');

    expect(reveal?.classList.contains('is-visible')).toBe(true);
    expect(reveal?.classList.contains('is-reveal-pending')).toBe(false);
  });
});
