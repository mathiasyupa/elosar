export type EloMood = 'idle' | 'ready' | 'thinking' | 'done';

/**
 * Elo's face: a small, calm robot drawn with the brand tokens. The mood is a
 * class on the root, and every movement lives in CSS (`.elo-bot-*`) so it
 * follows the theme and stops under prefers-reduced-motion.
 *
 *   idle      blinks now and then
 *   ready     raises a hand: "tengo una idea"
 *   thinking  antenna pulses, three dots
 *   done      happy eyes
 */
export function EloBot({ mood }: { mood: EloMood }) {
  return (
    <svg className={`elo-bot elo-bot-${mood}`} viewBox="0 0 64 64" role="img" aria-label="Elo">
      <line className="elo-bot-antenna" x1="32" y1="6" x2="32" y2="14" />
      <circle className="elo-bot-light" cx="32" cy="5" r="3" />
      <rect className="elo-bot-head" x="12" y="14" width="40" height="30" rx="12" />
      <rect className="elo-bot-visor" x="17" y="20" width="30" height="16" rx="8" />
      {mood === 'done' ? (
        <>
          <path className="elo-bot-smile-eye" d="M22 29 q3 -4 6 0" />
          <path className="elo-bot-smile-eye" d="M36 29 q3 -4 6 0" />
        </>
      ) : (
        <g className="elo-bot-eyes">
          <circle className="elo-bot-eye" cx="25" cy="28" r="3" />
          <circle className="elo-bot-eye" cx="39" cy="28" r="3" />
        </g>
      )}
      <path className="elo-bot-mouth" d="M27 40 q5 3 10 0" />
      <rect className="elo-bot-body" x="20" y="46" width="24" height="12" rx="6" />
      <line className="elo-bot-arm elo-bot-arm-left" x1="20" y1="50" x2="13" y2="56" />
      <line className="elo-bot-arm elo-bot-arm-right" x1="44" y1="50" x2="51" y2="56" />
      {mood === 'thinking' && (
        <g className="elo-bot-dots">
          <circle cx="50" cy="12" r="1.8" />
          <circle cx="55" cy="9" r="1.8" />
          <circle cx="60" cy="6" r="1.8" />
        </g>
      )}
    </svg>
  );
}
