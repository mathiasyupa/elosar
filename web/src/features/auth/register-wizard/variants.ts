import type { Variants } from 'framer-motion';

// `custom` direction: 1 = advancing (Siguiente), -1 = going back (Atrás).
// The incoming step slides in from the direction you're heading, the outgoing
// step slides out the opposite way — that's what sells the "sequential" feel.
export const stepVariants: Variants = {
  enter: (direction: number) => ({
    x: direction >= 0 ? 36 : -36,
    opacity: 0,
  }),
  center: {
    x: 0,
    opacity: 1,
    transition: { duration: 0.36, ease: [0.22, 1, 0.36, 1] },
  },
  exit: (direction: number) => ({
    x: direction >= 0 ? -36 : 36,
    opacity: 0,
    transition: { duration: 0.22, ease: [0.4, 0, 1, 1] },
  }),
};

// A quick horizontal shake — feedback when "Siguiente" is pressed on an invalid step.
export const shakeAnimation = {
  x: [0, -8, 8, -6, 6, -2, 2, 0],
  transition: { duration: 0.4 },
};
