import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { acceptTerms } from '@/api/auth';
import { Button } from '@/components/ui/Button';
import { useAuthStore } from '@/store/auth';
import { TermsConsent } from './TermsConsent';
import { AuthShell } from './components/AuthShell';

export function TermsReacceptPage() {
  const token = useAuthStore((state) => state.token);
  const legal = useAuthStore((state) => state.legal);
  const setIdentity = useAuthStore((state) => state.setIdentity);
  const setWorkspaces = useAuthStore((state) => state.setWorkspaces);
  const setLegal = useAuthStore((state) => state.setLegal);
  const navigate = useNavigate();
  const [accepted, setAccepted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!token) return <Navigate to="/login" replace />;
  if (!legal?.terms_acceptance_required) return <Navigate to="/dashboard" replace />;

  const accept = async () => {
    try {
      const response = await acceptTerms();
      setIdentity({ user: response.user, doctor: response.doctor, staffProfile: response.staff_profile });
      setWorkspaces(response.workspaces);
      setLegal(response.legal);
      navigate('/dashboard', { replace: true });
    } catch {
      setError('No pudimos registrar tu aceptación. Inténtalo nuevamente.');
    }
  };

  return (
    <AuthShell current="login" eyebrow="Actualización legal" title="Revisa y acepta los términos vigentes." description="Para proteger tu cuenta y continuar usando Elosar, confirma los documentos actualizados.">
      <TermsConsent error={error ?? undefined}>
        <input type="checkbox" checked={accepted} onChange={(event) => setAccepted(event.target.checked)} />
      </TermsConsent>
      <Button className="auth-submit" disabled={!accepted} onClick={() => void accept()}>Aceptar y continuar</Button>
    </AuthShell>
  );
}
