import { useEffect, useRef, useState } from 'react';

/**
 * The product film beside the hero headline: a real day in a clinic, recorded
 * from the app. It plays muted and looped (browsers only autoplay muted video,
 * and the film carries no soundtrack on the web), and starts paused with its
 * controls for anyone who asked the system for reduced motion.
 */
export function LandingHeroVideo() {
  const ref = useRef<HTMLVideoElement>(null);
  const [reduced] = useState(() => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);

  useEffect(() => {
    if (reduced) return;
    // Some browsers ignore the autoplay attribute after a soft navigation.
    void ref.current?.play().catch(() => {});
  }, [reduced]);

  return (
    <figure className="landing-hero-video">
      <video
        ref={ref}
        className="landing-hero-video-media"
        src="/video/elosar-demo.mp4"
        poster="/video/elosar-demo-poster.jpg"
        autoPlay={!reduced}
        muted
        loop
        playsInline
        preload="metadata"
        controls={reduced}
        aria-label="Video: un día en una clínica con Elosar, de la recepción a la caja"
      />
    </figure>
  );
}
