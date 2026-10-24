import { useEffect } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { exchangeImpersonation } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { Spinner } from '@/components/ui/Spinner';
import { BrandGlyph } from '@/components/BrandGlyph';
import { t } from '@/i18n/es';
import { isUsableAuthResponse } from './authExperience';

/**
 * Lands a support session started from the /superadmin "Impersonate" action
 * (?code=...). The code is a one-time exchange token, not a live bearer —
 * redeeming it mints the real session server-side, then this stores it
 * exactly like a normal login, replacing any session already open in this
 * browser (e.g. the platform_admin's own).
 */
export function ImpersonatePage() {
  const [params] = useSearchParams();
  const code = params.get('code') ?? '';
  const navigate = useNavigate();
  const setSession = useAuthStore((s) => s.setSession);

  const { data, isError } = useQuery({
    queryKey: ['impersonate', code],
    queryFn: () => exchangeImpersonation(code),
    enabled: code.length > 0,
    retry: false,
  });

  useEffect(() => {
    if (!data || !isUsableAuthResponse(data)) return;
    setSession({
      token: data.token,
      user: data.user,
      doctor: data.doctor,
      staffProfile: data.staff_profile,
      workspaces: data.workspaces,
    });
    navigate('/dashboard', { replace: true });
  }, [data, setSession, navigate]);

  if (!code) return <Navigate to="/login" replace />;

  return (
    <div className="auth-screen">
      <div className="auth-card" aria-busy={!isError && !data}>
        <div className="auth-brand">
          <BrandGlyph size={34} />
          <div>
            <div className="auth-brand-title">{t.appName}</div>
            <div className="auth-brand-tagline">{t.appTagline}</div>
          </div>
        </div>

        {isError || (data && !isUsableAuthResponse(data)) ? (
          <div className="alert red auth-impersonate-error" role="alert">
            <span className="ai">!</span>
            <p>Este enlace de acceso no es válido o ya expiró.</p>
          </div>
        ) : (
          <Spinner label="Iniciando sesión…" />
        )}
      </div>
    </div>
  );
}
