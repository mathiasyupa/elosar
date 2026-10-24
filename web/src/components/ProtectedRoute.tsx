import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuthStore } from '@/store/auth';
import { requiresTermsAcceptance } from '@/features/auth/authExperience';

export function ProtectedRoute() {
  const token = useAuthStore((state) => state.token);
  const legal = useAuthStore((state) => state.legal);
  const location = useLocation();
  // Remember the page: after signing in again the doctor lands back on it.
  if (!token) return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />;
  return requiresTermsAcceptance(legal) ? <Navigate to="/aceptar-terminos" replace /> : <Outlet />;
}
