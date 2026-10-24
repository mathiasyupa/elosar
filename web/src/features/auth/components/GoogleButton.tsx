/**
 * Starts the Google sign-in handshake.
 *
 * This is a full browser navigation, not an XHR: the backend answers with a
 * redirect to Google's consent screen, and the OAuth `state` it sets needs a
 * real session cookie on the API domain to be validated on the way back. The
 * route deliberately sits outside /api/v1 — it is a browser endpoint, not part
 * of the JSON API.
 */
const API_ORIGIN = import.meta.env.VITE_API_URL ?? 'http://localhost';

interface GoogleButtonProps {
  /** Copy changes between signing in and signing up; the flow is identical. */
  label?: string;
}

export function GoogleButton({ label = 'Continuar con Google' }: GoogleButtonProps) {
  return (
    <button
      type="button"
      className="auth-oauth"
      onClick={() => {
        window.location.href = `${API_ORIGIN}/auth/google/redirect`;
      }}
    >
      {label}
    </button>
  );
}
