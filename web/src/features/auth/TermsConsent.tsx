import { ReactNode } from 'react';

interface Props {
  /** The checkbox itself, so `register()` stays in the form that owns it. */
  children: ReactNode;
  error?: string;
}

/**
 * The acceptance checkbox, shared by the four ways into Elosar.
 *
 * It lives in one component because the wording is the thing being agreed to: four
 * copies would eventually say four slightly different things, and "they accepted
 * the terms" would stop having a single meaning.
 *
 * The links open in a new tab on purpose. Someone who has filled half a signup form
 * and taps "términos de servicio" should not come back to an empty form — losing
 * their work is a good way to teach them never to read it.
 *
 * Plain anchors, not <Link>: a target="_blank" navigation is a full document load
 * either way, so the router adds nothing here and would drag a router context into
 * every form that renders this — including the wizard step, which is tested on its
 * own without one.
 */
export function TermsConsent({ children, error }: Props) {
  return (
    <>
      <label className="auth-consent">
        {children}
        <span>
          Acepto los{' '}
          <a href="/terminos" target="_blank" rel="noreferrer">
            términos de servicio
          </a>{' '}
          y la{' '}
          <a href="/privacidad" target="_blank" rel="noreferrer">
            política de privacidad
          </a>{' '}
          de Elosar.
        </span>
      </label>
      {error && <p className="field-error">{error}</p>}
    </>
  );
}
