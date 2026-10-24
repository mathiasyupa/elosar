import { lazy, Suspense, type ReactElement } from 'react';
import { Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import { AppLayout } from '@/components/AppLayout';
import { RequirePermission } from '@/components/RequirePermission';
import { PATIENT_SECTION_ACCESS, ROUTE_ACCESS, type RouteKey } from '@/lib/routeAccess';
import { RequireClinicalModule } from '@/components/RequireClinicalModule';
import { Spinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/store/auth';

// Every page below used to be a static import, so the public landing — the
// highest-traffic, unauthenticated route — shipped the entire doctor app
// (billing, reports, the whole gyneco-obstetric module) before painting a
// single line of marketing copy. `lazy()` gives each page its own chunk;
// Vite/Rollup splits automatically, no bundler config needed for it.
const LandingPage = lazy(() => import('@/features/landing/LandingPage').then((m) => ({ default: m.LandingPage })));
const TermsPage = lazy(() => import('@/features/legal/TermsPage').then((m) => ({ default: m.TermsPage })));
const PrivacyPage = lazy(() => import('@/features/legal/PrivacyPage').then((m) => ({ default: m.PrivacyPage })));
const LoginPage = lazy(() => import('@/features/auth/LoginPage').then((m) => ({ default: m.LoginPage })));
const StaffRegisterPage = lazy(() =>
  import('@/features/auth/StaffRegisterPage').then((m) => ({ default: m.StaffRegisterPage })),
);
const ImpersonatePage = lazy(() =>
  import('@/features/auth/ImpersonatePage').then((m) => ({ default: m.ImpersonatePage })),
);
const GoogleCallbackPage = lazy(() =>
  import('@/features/auth/GoogleCallbackPage').then((m) => ({ default: m.GoogleCallbackPage })),
);
const DoctorRegisterPage = lazy(() =>
  import('@/features/auth/DoctorRegisterPage').then((m) => ({ default: m.DoctorRegisterPage })),
);
const RegisterChoicePage = lazy(() =>
  import('@/features/auth/RegisterChoicePage').then((m) => ({ default: m.RegisterChoicePage })),
);
const ClinicAdminRegisterPage = lazy(() =>
  import('@/features/auth/ClinicAdminRegisterPage').then((m) => ({ default: m.ClinicAdminRegisterPage })),
);
const ForgotPasswordPage = lazy(() =>
  import('@/features/auth/ForgotPasswordPage').then((m) => ({ default: m.ForgotPasswordPage })),
);
const ResetPasswordPage = lazy(() =>
  import('@/features/auth/ResetPasswordPage').then((m) => ({ default: m.ResetPasswordPage })),
);
const VerifyEmailNoticePage = lazy(() =>
  import('@/features/auth/VerifyEmailNoticePage').then((m) => ({ default: m.VerifyEmailNoticePage })),
);
const TermsReacceptPage = lazy(() =>
  import('@/features/auth/TermsReacceptPage').then((m) => ({ default: m.TermsReacceptPage })),
);
const ChangeTemporaryPasswordPage = lazy(() =>
  import('@/features/auth/ChangeTemporaryPasswordPage').then((m) => ({ default: m.ChangeTemporaryPasswordPage })),
);

const DashboardPage = lazy(() => import('@/features/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })));
const AgendaPage = lazy(() => import('@/features/agenda/AgendaPage').then((m) => ({ default: m.AgendaPage })));
const PatientsPage = lazy(() => import('@/features/patients/PatientsPage').then((m) => ({ default: m.PatientsPage })));
const ServicesPage = lazy(() => import('@/features/services/ServicesPage').then((m) => ({ default: m.ServicesPage })));
const DocumentsPage = lazy(() => import('@/features/documents/DocumentsPage').then((m) => ({ default: m.DocumentsPage })));

const PatientWorkspaceLayout = lazy(() =>
  import('@/features/patients/workspace/PatientWorkspaceLayout').then((m) => ({ default: m.PatientWorkspaceLayout })),
);
const PatientSummaryTab = lazy(() =>
  import('@/features/patients/workspace/PatientSummaryTab').then((m) => ({ default: m.PatientSummaryTab })),
);
const PatientHistoryTab = lazy(() =>
  import('@/features/patients/workspace/PatientHistoryTab').then((m) => ({ default: m.PatientHistoryTab })),
);
const RedirectToEncounter = lazy(() =>
  import('@/features/encounters/RedirectToEncounter').then((m) => ({ default: m.RedirectToEncounter })),
);
const PatientPrescriptionsTab = lazy(() =>
  import('@/features/patients/workspace/PatientPrescriptionsTab').then((m) => ({ default: m.PatientPrescriptionsTab })),
);
const PatientOrdersTab = lazy(() =>
  import('@/features/patients/workspace/PatientOrdersTab').then((m) => ({ default: m.PatientOrdersTab })),
);
const PatientGynecoTab = lazy(() =>
  import('@/features/patients/workspace/PatientGynecoTab').then((m) => ({ default: m.PatientGynecoTab })),
);
const PatientVaccinesTab = lazy(() =>
  import('@/features/patients/workspace/PatientVaccinesTab').then((m) => ({ default: m.PatientVaccinesTab })),
);
const PatientCertificatesTab = lazy(() =>
  import('@/features/patients/workspace/PatientCertificatesTab').then((m) => ({ default: m.PatientCertificatesTab })),
);
const PatientDocumentsTab = lazy(() =>
  import('@/features/patients/workspace/PatientDocumentsTab').then((m) => ({ default: m.PatientDocumentsTab })),
);
const LegacyConsultRedirect = lazy(() =>
  import('@/features/patients/workspace/LegacyConsultRedirect').then((m) => ({ default: m.LegacyConsultRedirect })),
);

const InvoicesPage = lazy(() => import('@/features/invoices/InvoicesPage').then((m) => ({ default: m.InvoicesPage })));
const InvoiceDetailPage = lazy(() =>
  import('@/features/invoices/InvoiceDetailPage').then((m) => ({ default: m.InvoiceDetailPage })),
);
const InsurancePage = lazy(() => import('@/features/insurance/InsurancePage').then((m) => ({ default: m.InsurancePage })));
const ReportsPage = lazy(() => import('@/features/reports/ReportsPage').then((m) => ({ default: m.ReportsPage })));
const ClinicsPage = lazy(() => import('@/features/clinics/ClinicsPage').then((m) => ({ default: m.ClinicsPage })));
const ClinicQueuePage = lazy(() =>
  import('@/features/clinic/ClinicQueuePage').then((m) => ({ default: m.ClinicQueuePage })),
);
const TeamPage = lazy(() => import('@/features/clinic/TeamPage').then((m) => ({ default: m.TeamPage })));
const TriagePage = lazy(() => import('@/features/clinic/TriagePage').then((m) => ({ default: m.TriagePage })));
const ProfilePage = lazy(() => import('@/features/profile/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SettingsPage = lazy(() => import('@/features/settings/SettingsPage').then((m) => ({ default: m.SettingsPage })));
const CrashTest = lazy(() => import('@/features/dev/CrashTest').then((m) => ({ default: m.CrashTest })));

/** Full-viewport fallback while a route chunk downloads. Kept intentionally
 *  bare — it is on screen for a fraction of a second on a warm connection. */
function RouteFallback() {
  return (
    <div className="route-fallback">
      <Spinner />
    </div>
  );
}

/** A section behind its permission (ROUTE_ACCESS): a stranger sees «sin acceso», never a half page. */
function guard(key: RouteKey, page: ReactElement) {
  return <RequirePermission permission={ROUTE_ACCESS[key]}>{page}</RequirePermission>;
}

/** A clinical section of the expediente: without the permission, «sin acceso» instead of an empty «0 consultas». */
function patientSection(key: keyof typeof PATIENT_SECTION_ACCESS, page: ReactElement) {
  return <RequirePermission permission={PATIENT_SECTION_ACCESS[key]}>{page}</RequirePermission>;
}

/** Keep the temporary credential confined to the password replacement page. */
function TemporaryPasswordGate() {
  const required = useAuthStore((state) => state.legal?.password_change_required);
  return required ? <Navigate to="/cambiar-clave-temporal" replace /> : <Outlet />;
}

export function App() {
  return (
    <Suspense fallback={<RouteFallback />}>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        {/* Linked from the acceptance checkbox on every signup path. They live on
            elosar.site, not on the API domain: a doctor sent to a different-looking
            site to read what they are agreeing to learns the wrong habit. */}
        <Route path="/terminos" element={<TermsPage />} />
        <Route path="/privacidad" element={<PrivacyPage />} />
        <Route path="/login" element={<LoginPage />} />
        {/* The path is mirrored in AppServiceProvider::configurePasswordResetNotification
            on the backend, which builds the link the e-mail points at. */}
        <Route path="/olvide-contrasena" element={<ForgotPasswordPage />} />
        <Route path="/restablecer-contrasena" element={<ResetPasswordPage />} />
        <Route path="/verifica-tu-correo" element={<VerifyEmailNoticePage />} />
        <Route path="/crear-cuenta" element={<RegisterChoicePage />} />
        <Route path="/crear-cuenta/medico" element={<DoctorRegisterPage />} />
        <Route path="/crear-cuenta/clinica" element={<ClinicAdminRegisterPage />} />
        <Route path="/registro" element={<StaffRegisterPage />} />
        <Route path="/impersonate" element={<ImpersonatePage />} />
        {/* Where the backend's Google callback sends the browser, carrying a
            one-time code. Covered by the SPA rewrite in vercel.json. */}
        <Route path="/auth/google/callback" element={<GoogleCallbackPage />} />
        <Route path="/cambiar-clave-temporal" element={<ChangeTemporaryPasswordPage />} />

        <Route element={<TemporaryPasswordGate />}>
        <Route path="/aceptar-terminos" element={<TermsReacceptPage />} />

        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/agenda" element={guard('agenda', <AgendaPage />)} />
            <Route path="/servicios" element={guard('services', <ServicesPage />)} />

            {/* Patients + unified clinical expediente (banner + tabs). */}
            <Route path="/pacientes" element={guard('patients', <PatientsPage />)} />
            <Route path="/pacientes/:patientId" element={guard('patients', <PatientWorkspaceLayout />)}>
              <Route index element={<Navigate to="resumen" replace />} />
              <Route path="resumen" element={<PatientSummaryTab />} />
              <Route path="historial" element={patientSection('historial', <PatientHistoryTab />)} />
              {/* PatientWorkspaceLayout owns the atención screen and keeps it mounted
                  across sections; the route only marks it active. */}
              <Route path="atencion" element={null} />
              {/* La atención vivió en /atencion/:patientId y, antes, aquí mismo
                  en /consulta; esta ruta sobrevive para enlaces guardados y
                  pestañas abiertas de esa migración anterior. */}
              <Route path="consulta" element={<RedirectToEncounter />} />
              <Route path="recetas" element={patientSection('recetas', <PatientPrescriptionsTab />)} />
              <Route path="ordenes" element={patientSection('ordenes', <PatientOrdersTab />)} />
              <Route
                path="gineco"
                element={patientSection(
                  'gineco',
                  <RequireClinicalModule module="gyneco_obstetrics">
                    <PatientGynecoTab />
                  </RequireClinicalModule>,
                )}
              />
              <Route path="vacunas" element={<PatientVaccinesTab />} />
              <Route path="certificados" element={patientSection('certificados', <PatientCertificatesTab />)} />
              <Route path="documentos" element={patientSection('documentos', <PatientDocumentsTab />)} />
            </Route>

            <Route path="/documentos" element={guard('documents', <DocumentsPage />)} />

            {/* Legacy clinical routes → unified expediente. */}
            <Route path="/consultas" element={<LegacyConsultRedirect />} />
            <Route path="/historia" element={<Navigate to="/pacientes" replace />} />
            <Route path="/prescripciones" element={<Navigate to="/pacientes" replace />} />
            <Route path="/estudios" element={<Navigate to="/pacientes" replace />} />

            <Route path="/facturas" element={guard('invoices', <InvoicesPage />)} />
            <Route path="/facturas/:id" element={guard('invoices', <InvoiceDetailPage />)} />
            {/* Cobros was folded into Facturas — kept as a redirect for old links. */}
            <Route path="/cobros" element={<Navigate to="/facturas" replace />} />
            <Route path="/seguros" element={guard('insurance', <InsurancePage />)} />
            <Route path="/reportes" element={guard('reports', <ReportsPage />)} />
            {/* Vigilancia now lives as a tab inside Reportes. */}
            <Route path="/vigilancia" element={<Navigate to="/reportes?tab=vigilancia" replace />} />
            <Route path="/clinicas" element={<ClinicsPage />} />

            {/* Clinic operations */}
            <Route path="/cola" element={guard('queue', <ClinicQueuePage />)} />
            <Route path="/equipo" element={guard('team', <TeamPage />)} />
            <Route path="/triaje/:appointmentId" element={guard('triage', <TriagePage />)} />

            <Route path="/perfil" element={<ProfilePage />} />
            <Route path="/ajustes" element={guard('settings', <SettingsPage />)} />

            {/* Error-boundary harness. Dev only — stripped from production builds. */}
            {import.meta.env.DEV && <Route path="/dev/crash" element={<CrashTest />} />}
          </Route>
        </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
  );
}
