import { useEffect, useRef, useState } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { fetchMe, logout as apiLogout } from '@/api/auth';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities, ROLE_LABELS } from '@/lib/capabilities';
import { preferredWorkspace } from '@/lib/workspaceAccess';
import { WorkspaceAccessBanner } from '@/features/subscription/WorkspaceAccessBanner';
import { ErrorBoundary } from './ErrorBoundary';
import { QuickPatientSearch } from './QuickPatientSearch';
import { AiAssistantChat } from '@/features/ai-assistant/AiAssistantChat';
import { Toaster } from './Toaster';
import { HonorificPrompt } from './HonorificPrompt';
import { useTheme } from '@/lib/theme';
import { setMonitoringUser } from '@/lib/monitoring';
import type { AccountMenuProps } from './shell/AccountMenu';
import { ContextualTopbar } from './shell/ContextualTopbar';
import { buildVisibleNavigation } from './shell/navigation';
import { PageActionProvider, usePageAction } from './shell/PageActionContext';
import { getRouteContext, isFocusRoute } from './shell/routeContext';
import { SidebarNavigation } from './shell/SidebarNavigation';
import type { SwitchableDoctor } from '@/api/types';
import { rememberDoctor } from '@/features/auth/recentDoctors';

export function AppLayout() {
  return (
    <PageActionProvider>
      <AuthenticatedShell />
    </PageActionProvider>
  );
}

function AuthenticatedShell() {
  const user = useAuthStore((state) => state.user);
  const doctor = useAuthStore((state) => state.doctor);
  const staffProfile = useAuthStore((state) => state.staffProfile);
  const navigate = useNavigate();
  const location = useLocation();
  const openQuickSearch = useUiStore((s) => s.openQuickSearch);
  const logout = useAuthStore((state) => state.logout);
  const activeWorkspaceId = useAuthStore((state) => state.activeWorkspaceId);
  const setWorkspaces = useAuthStore((state) => state.setWorkspaces);
  const setIdentity = useAuthStore((state) => state.setIdentity);
  const setLegal = useAuthStore((state) => state.setLegal);
  const setActiveWorkspace = useAuthStore((state) => state.setActiveWorkspace);
  const { theme, toggle } = useTheme();
  const [search, setSearch] = useState('');
  const actions = usePageAction();
  const navTriggerRef = useRef<HTMLButtonElement>(null);
  // Mobile nav drawer: hidden off-canvas on phones, toggled by the topbar
  // hamburger. Closes automatically whenever the route changes.
  const [navOpen, setNavOpen] = useState(false);
  useEffect(() => {
    setNavOpen(false);
  }, [location.pathname]);

  const caps = useWorkspaceCapabilities();
  const isDoctorAccount = user?.account_type === 'doctor';
  const workspaces = useAuthStore((state) => state.workspaces);
  const activeWorkspace = workspaces.find((w) => w.id === activeWorkspaceId) ?? null;

  // Refresh identity + accessible workspaces on mount and self-heal a stale
  // selection: if the persisted active workspace no longer exists (clinic
  // deleted, membership revoked), fall back to the personal workspace (or the
  // first clinic, for staff) so the app never sends an invalid X-Workspace-Id.
  const { data: me } = useQuery({ queryKey: ['me'], queryFn: fetchMe, staleTime: 60_000 });
  useEffect(() => {
    if (!me) return;
    setIdentity({ user: me.user, doctor: me.doctor, staffProfile: me.staff_profile });
    setLegal(me.legal);
    // Tag crash reports with the account id + workspace (never name or email),
    // so a Sentry issue can be traced without exporting personal data.
    setMonitoringUser({ id: me.user.id, workspaceId: activeWorkspaceId });
    setWorkspaces(me.workspaces);
    // Every doctor who works on this computer shows up on its login screen
    // («Médicos vistos en este equipo»). Only «Cambiar de médico» fed that list
    // before, so on the clinic computer — where each doctor types their own
    // email — it stayed empty.
    if (me.doctor) {
      void rememberDoctor({ name: me.doctor.name, email: me.user.email, photo_url: me.doctor.photo_url ?? null });
    }
    const ids = me.workspaces.map((w) => w.id);
    if (activeWorkspaceId === null || !ids.includes(activeWorkspaceId)) {
      const fallback = preferredWorkspace(me.workspaces);
      if (fallback) setActiveWorkspace(fallback.id);
    }
  }, [me, activeWorkspaceId, setWorkspaces, setIdentity, setActiveWorkspace]);

  const handleLogout = async () => {
    try {
      await apiLogout();
    } catch {
      // Ignore network errors — we clear the local session regardless.
    }
    logout();
    navigate('/login', { replace: true });
  };

  const handleSwitchDoctor = async (nextDoctor: SwitchableDoctor) => {
    await rememberDoctor(nextDoctor);
    await handleLogout();
    navigate(`/login?email=${encodeURIComponent(nextDoctor.email)}`, { replace: true });
  };

  // No sign-out by inactivity (ADR-021). A 15-minute idle logout lived here:
  // it counted activity per tab, so a patient tab left quiet signed the doctor
  // out of every tab while they worked in another, and a short break meant
  // signing in again. The session now ends only with «Cerrar sesión» or
  // «Cambiar de médico» (or after weeks unused, server-side).

  const submitSearch = () => {
    const term = search.trim();
    if (term) navigate(`/pacientes?q=${encodeURIComponent(term)}`);
  };

  const groups = buildVisibleNavigation({
    isClinic: caps.isClinic,
    isDoctorAccount,
    can: caps.can,
    canAny: caps.canAny,
  });
  const routeContext = getRouteContext(location.pathname);
  // Atender se pinta sin armazón: el paciente está delante y la navegación
  // sólo invita a irse a otra cosa. Se esconde el cromo, no la infraestructura —
  // el refresco de identidad, la autocuración del workspace activo, los toasts
  // y los límites de error siguen montados igual.
  const focusMode = isFocusRoute(location.pathname);
  // Inside one patient the sections share a key: the expediente (and the
  // atención it keeps mounted) must survive moving between them. Everywhere
  // else every route change still remounts and resets its screen.
  const patientRoot = location.pathname.match(/^\/pacientes\/[^/]+/)?.[0];
  const viewKey = focusMode && patientRoot ? patientRoot : location.pathname;

  const displayName = doctor?.name ?? user?.name ?? '';
  const displaySub = doctor?.specialty ?? staffProfile?.job_title ?? null;
  const roleBadge =
    caps.isClinic && caps.roles.length
      ? `${caps.roles.map((r) => ROLE_LABELS[r]).join(' · ')}${caps.isOwner ? ' · Propietario' : ''} · ${caps.workspace?.name ?? ''}`
      : null;
  const account: AccountMenuProps = {
    displayName,
    displaySub,
    photoUrl: doctor?.photo_url ?? null,
    canOpenProfile: isDoctorAccount,
    theme,
    onToggleTheme: toggle,
    onLogout: () => void handleLogout(),
    canSwitchDoctor: Boolean(caps.isClinic),
    onSwitchDoctor: (nextDoctor) => void handleSwitchDoctor(nextDoctor),
  };

  return (
    <div className={focusMode ? 'app app-focus' : 'app'}>
      {!focusMode && (
        <SidebarNavigation
          groups={groups}
          open={navOpen}
          roleBadge={roleBadge}
          returnFocusRef={navTriggerRef}
          onClose={() => setNavOpen(false)}
        />
      )}

      <div className="main">
        {!focusMode && (
          <ContextualTopbar
            context={routeContext}
            search={search}
            actions={actions}
            account={account}
            navigationOpen={navOpen}
            navigationTriggerRef={navTriggerRef}
            onSearchChange={setSearch}
            onSearchSubmit={submitSearch}
            onOpenQuickSearch={openQuickSearch}
            onOpenNavigation={() => setNavOpen(true)}
          />
        )}

        <main className="content" id="view">
          {/* Per-section boundary: a crash in one screen is contained here, so
              the sidebar, topbar and workspace switcher stay usable and the
              doctor can navigate away instead of reloading. The key on the
              wrapper remounts (and therefore resets) it on every route change. */}
          {/* Mounted once, above every screen: the doctor should learn their
              space is running out (or already read-only) wherever they are,
              not only if they happen to open settings. */}
          <WorkspaceAccessBanner workspace={activeWorkspace} />

          <div className="view-swap" key={viewKey}>
            <ErrorBoundary boundary={`route:${location.pathname}`} variant="section">
              <Outlet />
            </ErrorBoundary>
          </div>
        </main>
      </div>

      {/* Auxiliary widgets: if one breaks it should vanish, not take the shell. */}
      <ErrorBoundary boundary="quick-search" variant="silent">
        <QuickPatientSearch />
      </ErrorBoundary>
      {caps.can('assistant.use') && (
        <ErrorBoundary boundary="ai-assistant" variant="silent">
          <AiAssistantChat />
        </ErrorBoundary>
      )}
      <Toaster />
      <HonorificPrompt />
    </div>
  );
}
