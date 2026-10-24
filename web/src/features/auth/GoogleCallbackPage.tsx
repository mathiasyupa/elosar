import { useEffect } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { exchangeGoogleCode, needsRegistration } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { Spinner } from '@/components/ui/Spinner';
import { BrandGlyph } from '@/components/BrandGlyph';
import { t } from '@/i18n/es';
import { isUsableAuthResponse } from './authExperience';

/**
 * Where the Google handshake lands (?code=...).
 *
 * The code is a one-time exchange token, not a live bearer: redeeming it here is
 * what mints the session. Two outcomes, because a Google identity is not an
 * account — an existing user signs straight in, and a new one is handed to the
 * sign-up wizard with the code still in play.
 */
export function GoogleCallbackPage() {
  const [params] = useSearchParams();
  const code = params.get('code') ?? '';
  const navigate = useNavigate();
  const setSession = useAuthStore((s) => s.setSession);

  const { data, isError } = useQuery({
    queryKey: ['google-exchange', code],
    queryFn: () => exchangeGoogleCode(code),
    enabled: code.length > 0,
    retry: false,
  });

  useEffect(() => {
    if (!data) return;

    // Verified by Google, but there is no account behind it yet. The wizard
    // carries the code onward and presents it again when it submits.
    if (needsRegistration(data)) {
      navigate(`/crear-cuenta?google=${encodeURIComponent(code)}`, { replace: true });
      return;
    }

    if (!isUsableAuthResponse(data)) return;

    setSession({
      token: data.token,
      user: data.user,
      doctor: data.doctor,
      staffProfile: data.staff_profile,
      workspaces: data.workspaces,
    });
    navigate('/dashboard', { replace: true });
  }, [data, code, setSession, navigate]);

  if (!code) return <Navigate to="/login" replace />;

  const failed = isError || (data && !needsRegistration(data) && !isUsableAuthResponse(data));

  return (
    <div className="auth-screen">
      <div className="auth-card" aria-busy={!failed && !data}>
        <div className="auth-brand">
          <BrandGlyph size={34} />
          <div>
            <div className="auth-brand-title">{t.appName}</div>
            <div className="auth-brand-tagline">{t.appTagline}</div>
          </div>
        </div>

        {failed ? (
          <div className="alert red auth-impersonate-error" role="alert">
            <span className="ai">!</span>
            <p>No pudimos completar el acceso con Google. Vuelve a intentarlo desde el inicio de sesión.</p>
          </div>
        ) : (
          <Spinner label="Entrando con Google…" />
        )}
      </div>
    </div>
  );
}
