<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppointmentImportController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EloController;
use App\Http\Controllers\Api\EloRulesController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\FinanceEntryController;
use App\Http\Controllers\Api\GynecoHistoryController;
use App\Http\Controllers\Api\ImmunizationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MedicalCertificateController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MedicalServiceController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PatientAlertController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientImportController;
use App\Http\Controllers\Api\PatientVitalObservationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PregnancyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RdacaaController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StudyOrderController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Middleware\SetAuditUser;
use App\Services\Elo\EloAgents;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (doctor web/mobile app)
|--------------------------------------------------------------------------
|
| Token-based (Sanctum). Three concentric layers:
|   1. public                 — login only
|   2. auth:sanctum           — identity + profile, no workspace selected yet
|   3. auth:sanctum+workspace  — tenant data, requires X-Workspace-Id header
|
| All routes are prefixed with /api/v1. Tenant isolation for layer 3 is enforced
| entirely by WorkspaceScope (bound by the `workspace` middleware) — controllers
| never filter by workspace_id manually.
|
*/

Route::prefix('v1')->group(function (): void {
    // --- Public ---------------------------------------------------------
    // Credential endpoints carry a strict per-(email+IP) throttle to stop
    // brute-force / credential stuffing (see RateLimiter 'login').
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Doctor self-registration (creates User+Doctor, auto personal workspace).
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login');
    Route::get('specialties', [CatalogController::class, 'specialties']);
    // Public write (self-registration form) — throttled to prevent catalog spam.
    Route::post('specialties', [CatalogController::class, 'storeSpecialty'])->middleware('throttle:login');
    // Clinic-administrator self-registration (non-doctor owner: creates
    // User+StaffProfile+clinic, no personal workspace).
    Route::post('auth/register/clinic-admin', [AuthController::class, 'registerClinicAdmin'])->middleware('throttle:login');
    // Staff onboarding via a clinic invitation token (no auth yet).
    Route::get('auth/invite/{token}', [AuthController::class, 'showInvite']);
    Route::post('auth/register/staff', [AuthController::class, 'registerStaff'])->middleware('throttle:login');
    // Redeems a /superadmin "Impersonate" one-time code for a real session
    // (throttled — a code is single-use anyway, this just caps guessing).
    Route::post('auth/impersonate', [AuthController::class, 'exchangeImpersonation'])->middleware('throttle:login');
    // Redeems the one-time code left by the Google OAuth callback (which lives in
    // routes/web.php, where the session can validate the handshake `state`).
    // Either logs in an existing account or reports that sign-up must finish.
    Route::post('auth/google/exchange', [AuthController::class, 'exchangeGoogle'])->middleware('throttle:login');
    // Password recovery for the SPA. The Breeze equivalents in routes/auth.php
    // are session + CSRF and render Blade on the API domain; these speak JSON.
    // Throttled like every other credential endpoint: without it, this is a free
    // mailer aimed at any address an attacker names.
    Route::post('auth/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:login');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:login');
    // E-mail confirmation. The link is clicked from an inbox, with no session,
    // so this is public and validates the signature itself (see the controller).
    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:login');
    Route::get('auth/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('api.verification.verify');

    // --- Authenticated (no active workspace required) -------------------
    Route::middleware(['auth:sanctum', 'terms.accepted', 'temporary_password.changed'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');
        // Evicts sessions on devices the doctor no longer has (a lost phone).
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/accept-terms', [AuthController::class, 'acceptTerms']);
        Route::post('auth/change-temporary-password', [PasswordResetController::class, 'changeTemporary'])
            ->middleware('throttle:login');
        Route::get('workspaces', [WorkspaceController::class, 'index']);

        // Role reference table for the team screen (static, derived from the
        // permission matrix — no active workspace required).
        Route::get('roles-catalog', [CatalogController::class, 'roles']);

        // Own professional profile — workspace-agnostic.
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('profile/signature', [ProfileController::class, 'uploadSignature']);
        Route::delete('profile/signature', [ProfileController::class, 'deleteSignature']);
        Route::post('profile/photo', [ProfileController::class, 'uploadPhoto']);
        Route::delete('profile/photo', [ProfileController::class, 'deletePhoto']);

        // Clinics — creating/joining a workspace happens BEFORE one is active,
        // so these run without the `workspace` middleware.
        Route::post('clinics', [ClinicController::class, 'store']);
        Route::post('clinics/join', [ClinicController::class, 'join']);
        Route::get('clinics/{id}/invite-code', [ClinicController::class, 'inviteCode'])->whereNumber('id');
        Route::post('clinics/{id}/regenerate-code', [ClinicController::class, 'regenerateCode'])->whereNumber('id');

        // --- Tenant data (active workspace bound from X-Workspace-Id) ----
        // Every route is gated by `permission:<perm>` (EnsurePermission) on top of
        // the WorkspaceScope tenant isolation. The permission matrix lives in
        // WorkspacePermissionService — the single source of truth.
        // `workspace.writable` turns every unsafe method in this group into a
        // 423 once the workspace is commercially read-only. A handful of writes
        // opt out below: finishing what is already in flight is not the same as
        // starting something new, and blocking it would leave a consultation,
        // an invoice or an SRI voucher stranded half-done.
        Route::middleware(['workspace', 'workspace.writable'])->group(function (): void {
            // Dashboard & reports
            Route::get('dashboard/stats', [DashboardController::class, 'stats'])->middleware('permission:dashboard.view');

            // "Primeros pasos" checklist. Steps are derived from real data and
            // assembled from the caller's own permissions.
            Route::get('onboarding', [OnboardingController::class, 'show'])->middleware('permission:dashboard.view');
            Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss'])->middleware('permission:dashboard.view');
            Route::get('reports/revenue', [ReportController::class, 'revenue'])->middleware('permission:reports.view');
            // Day-level finances for the selected period (cash close + daily series).
            Route::get('reports/daily', [ReportController::class, 'daily'])->middleware('permission:reports.view');
            // Doctor settlement for a period, aggregated and attention by attention.
            Route::get('reports/settlement', [ReportController::class, 'settlement'])->middleware('permission:reports.view');
            // The clinic's books (income − commissions − expenses). Gated by
            // finance.view: owner / admin only, never the cash desk.
            Route::get('reports/pnl', [ReportController::class, 'pnl'])->middleware('permission:finance.view');
            Route::get('finance-entries', [FinanceEntryController::class, 'index'])->middleware('permission:finance.view');
            Route::post('finance-entries', [FinanceEntryController::class, 'store'])->middleware('permission:finance.manage');
            Route::put('finance-entries/{id}', [FinanceEntryController::class, 'update'])->whereNumber('id')->middleware('permission:finance.manage');
            Route::delete('finance-entries/{id}', [FinanceEntryController::class, 'destroy'])->whereNumber('id')->middleware('permission:finance.manage');
            // Clinical / epidemiological reports ("Vig. de Salud") + MSP RDACAA export.
            // Gated by reports.clinical (clinical audience) — NOT reports.view, so the
            // cashier (billing) never receives diagnoses / patient-level clinical data.
            Route::get('reports/clinical', [ReportController::class, 'clinical'])->middleware('permission:reports.clinical');
            Route::get('reports/attentions', [ReportController::class, 'attentions'])->middleware('permission:reports.clinical');
            Route::get('reports/rdacaa', [RdacaaController::class, 'export'])->middleware('permission:reports.clinical');

            // Workspace settings (fiscal / SRI / letterhead). Reading is open to
            // members who can see the config (settings.view); writing is
            // owner-only (settings.manage).
            Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:settings.view');
            // Sample PDF to check how the letterhead prints, without needing a
            // real patient. Read-only, so settings.view is enough.
            Route::get('settings/letterhead/preview', [SettingsController::class, 'previewLetterhead'])->middleware('permission:settings.view');
            // The real document with UNSAVED settings, for the live preview in
            // Ajustes → Papelería. Builds everything in memory; writes nothing.
            Route::post('settings/letterhead/preview/{document}', [SettingsController::class, 'previewDocument'])
                ->whereIn('document', ['prescription', 'certificate', 'order'])
                ->middleware('permission:settings.view');
            // The clinic paper is shared by every doctor, so whoever runs the front
            // desk may set it up too; fiscal data and the SRI stay owner-only.
            Route::middleware('permission:settings.letterhead')->group(function (): void {
                Route::put('settings/letterhead', [SettingsController::class, 'updateLetterhead']);
                Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
                Route::delete('settings/logo', [SettingsController::class, 'deleteLogo']);
                Route::post('settings/header-image', [SettingsController::class, 'uploadHeaderImage']);
                Route::delete('settings/header-image', [SettingsController::class, 'deleteHeaderImage']);
                Route::post('settings/sheet-image', [SettingsController::class, 'uploadSheetImage']);
                Route::delete('settings/sheet-image', [SettingsController::class, 'deleteSheetImage']);
            });
            Route::middleware('permission:settings.manage')->group(function (): void {
                Route::put('settings', [SettingsController::class, 'update']);
                Route::post('settings/certificate', [SettingsController::class, 'uploadCertificate']);
                // Diagnóstico de la configuración SRI. En pruebas emite un
                // comprobante desechable para saber qué opina el SRI antes de
                // que exista ninguna factura real.
                Route::post('settings/sri/check', [SettingsController::class, 'checkSri']);
            });

            // Catalogs — available to anyone with a base operational role.
            Route::get('medical-services', [CatalogController::class, 'medicalServices'])->middleware('permission:dashboard.view');
            // A doctor or the clinic can add the services they provide (no admin panel needed).
            Route::post('medical-services', [MedicalServiceController::class, 'store'])->middleware('permission:appointments.manage');
            Route::put('medical-services/{id}', [MedicalServiceController::class, 'update'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::delete('medical-services/{id}', [MedicalServiceController::class, 'destroy'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::get('cie10/categories', [CatalogController::class, 'cie10Categories'])->middleware('permission:dashboard.view');
            Route::get('cie10', [CatalogController::class, 'cie10'])->middleware('permission:dashboard.view');
            Route::get('medications', [CatalogController::class, 'medications'])->middleware('permission:records.view');
            Route::post('medications', [CatalogController::class, 'storeMedication'])->middleware('permission:records.manage');
            Route::delete('medications/{id}', [CatalogController::class, 'destroyMedication'])->whereNumber('id')->middleware('permission:records.manage');
            // Orderable studies catalog (lab / imaging): global + workspace custom.
            Route::get('studies', [CatalogController::class, 'studies'])->middleware('permission:records.view');
            Route::post('studies', [CatalogController::class, 'storeStudy'])->middleware('permission:records.manage');
            Route::delete('studies/{id}', [CatalogController::class, 'destroyStudy'])->whereNumber('id')->middleware('permission:records.manage');
            // Vaccine catalog (rows of the PAI vaccination card)
            Route::get('vaccines', [ImmunizationController::class, 'vaccines'])->middleware('permission:vitals.create');

            // Agenda
            Route::get('appointments', [AppointmentController::class, 'index'])->middleware('permission:appointments.view');
            Route::post('appointments', [AppointmentController::class, 'store'])->middleware('permission:appointments.manage');
            Route::post('appointments/import', [AppointmentImportController::class, 'store'])->middleware('permission:appointments.manage');
            Route::post('appointments/walk-in', [AppointmentController::class, 'walkIn'])->middleware('permission:appointments.manage');
            Route::get('appointments/{id}', [AppointmentController::class, 'show'])->whereNumber('id')->middleware('permission:appointments.view');
            Route::put('appointments/{id}', [AppointmentController::class, 'update'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::post('appointments/{id}/cancel', [AppointmentController::class, 'cancel'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::post('appointments/{id}/complete', [AppointmentController::class, 'complete'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::post('appointments/{id}/check-in', [AppointmentController::class, 'checkIn'])->whereNumber('id')->middleware('permission:appointments.checkin');
            Route::post('appointments/{id}/mark-reminded', [AppointmentController::class, 'markReminded'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::post('appointments/{id}/send-confirmation-email', [AppointmentController::class, 'sendConfirmationEmail'])->whereNumber('id')->middleware('permission:appointments.manage');
            Route::post('appointments/{id}/send-reminder-email', [AppointmentController::class, 'sendReminderEmail'])->whereNumber('id')->middleware('permission:appointments.manage');

            // Clinic operations — day queue & team roster
            Route::get('clinic/queue', [ClinicController::class, 'queue'])->middleware('permission:appointments.view');
            Route::get('clinic/next-patient', [ClinicController::class, 'nextPatient'])->middleware('permission:records.manage');
            Route::get('clinic/team', [ClinicController::class, 'team'])->middleware('permission:team.view');
            // Shared clinic computers can choose a doctor to prefill the next
            // login. This intentionally exposes less than the team roster.
            Route::get('clinic/switchable-doctors', [ClinicController::class, 'switchableDoctors'])->middleware('permission:dashboard.view');

            // Team administration — owner only (team.manage)
            Route::post('clinic/invite', [TeamController::class, 'invite'])->middleware('permission:team.manage');
            Route::put('clinic/team/{memberId}', [TeamController::class, 'updateMember'])->whereNumber('memberId')->middleware('permission:team.manage');
            // Per-service commission overrides for a member doctor.
            Route::get('clinic/team/{memberId}/commission-rules', [TeamController::class, 'commissionRules'])->whereNumber('memberId')->middleware('permission:team.manage');
            Route::put('clinic/team/{memberId}/commission-rules', [TeamController::class, 'setCommissionRules'])->whereNumber('memberId')->middleware('permission:team.manage');

            // Patients — demographics view/manage. Clinical history is records.view.
            Route::get('patients', [PatientController::class, 'index'])->middleware('permission:patients.view');
            Route::get('patients/celebrations', [PatientController::class, 'celebrations'])->middleware('permission:patients.view');
            // Ordered follow-ups that never became an appointment (call list).
            Route::get('patients/pending-follow-ups', [PatientController::class, 'pendingFollowUps'])->middleware('permission:patients.view');
            Route::post('patients/{id}/greeting-email', [PatientController::class, 'greetingEmail'])->whereNumber('id')->middleware('permission:patients.view');
            Route::post('patients', [PatientController::class, 'store'])->middleware('permission:patients.manage');
            Route::post('patients/import', [PatientImportController::class, 'store'])->middleware('permission:patients.manage');
            // A directory reset is distinct from day-to-day patient management:
            // it is owner-only, server-scoped and has an explicit challenge.
            Route::post('patients/purge-preview', [PatientController::class, 'purgePreview'])->middleware('permission:patients.purge');
            Route::post('patients/purge', [PatientController::class, 'purge'])
                ->middleware([SetAuditUser::class, 'permission:patients.purge']);
            Route::get('patients/{id}', [PatientController::class, 'show'])->whereNumber('id')->middleware('permission:patients.view');
            Route::put('patients/{id}', [PatientController::class, 'update'])->whereNumber('id')->middleware('permission:patients.manage');
            // Says what a delete would do before it does it: a patient with no
            // clinical trace is removed for real, one with history is archived.
            Route::post('patients/deletion-preview', [PatientController::class, 'deletionPreview'])->middleware('permission:patients.manage');
            Route::delete('patients/{id}', [PatientController::class, 'destroy'])->whereNumber('id')->middleware('permission:patients.manage');
            Route::get('patients/{id}/baseline-vitals', [PatientController::class, 'baselineVitals'])->whereNumber('id')->middleware('permission:vitals.create');
            Route::post('patients/{id}/vital-observations', [PatientVitalObservationController::class, 'store'])->whereNumber('id')->middleware('permission:vitals.create');
            Route::get('patients/{id}/records', [PatientController::class, 'records'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('patients/{id}/allergies', [PatientController::class, 'storeAllergy'])->whereNumber('id')->middleware('permission:patients.manage');
            Route::delete('patients/{id}/allergies/{allergyId}', [PatientController::class, 'destroyAllergy'])->whereNumber(['id', 'allergyId'])->middleware('permission:patients.manage');
            Route::post('patients/{id}/family-background', [PatientController::class, 'storeFamilyBackground'])->whereNumber('id')->middleware('permission:patients.manage');
            Route::delete('patients/{id}/family-background/{backgroundId}', [PatientController::class, 'destroyFamilyBackground'])->whereNumber(['id', 'backgroundId'])->middleware('permission:patients.manage');

            // Vaccination card (PAI) — clinical administration by doctors & nurses
            Route::get('patients/{id}/immunizations', [ImmunizationController::class, 'index'])->whereNumber('id')->middleware('permission:vitals.create');
            Route::post('patients/{id}/immunizations', [ImmunizationController::class, 'store'])->whereNumber('id')->middleware('permission:vitals.create');
            Route::delete('immunizations/{id}', [ImmunizationController::class, 'destroy'])->whereNumber('id')->middleware('permission:vitals.create');

            // Standing patient alerts. Read with records.view so they can be shown
            // wherever the patient is; written with records.manage.
            Route::get('patients/{id}/alerts', [PatientAlertController::class, 'index'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('patients/{id}/alerts', [PatientAlertController::class, 'store'])->whereNumber('id')->middleware('permission:records.manage');
            Route::post('patient-alerts/{id}/resolve', [PatientAlertController::class, 'resolve'])->whereNumber('id')->middleware('permission:records.manage');
            Route::delete('patient-alerts/{id}', [PatientAlertController::class, 'destroy'])->whereNumber('id')->middleware('permission:records.manage');

            // Gyneco-obstetric history — the patient's, not each consultation's.
            // Under records.* and NOT patients.manage on purpose: the front desk
            // holds patients.manage, and this is not front-desk data (LOPDP).
            Route::get('patients/{id}/gyneco-history', [GynecoHistoryController::class, 'show'])->whereNumber('id')->middleware(['permission:records.view', 'specialty.capability:gyneco_obstetrics']);
            Route::put('patients/{id}/gyneco-history', [GynecoHistoryController::class, 'update'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);

            // Pregnancies. A pregnancy is an entity with a lifecycle, not a block
            // on the patient card that the next one overwrites.
            Route::get('patients/{id}/pregnancies', [PregnancyController::class, 'index'])->whereNumber('id')->middleware(['permission:records.view', 'specialty.capability:gyneco_obstetrics']);
            Route::post('patients/{id}/pregnancies', [PregnancyController::class, 'store'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);
            Route::get('pregnancies/{id}', [PregnancyController::class, 'show'])->whereNumber('id')->middleware(['permission:records.view', 'specialty.capability:gyneco_obstetrics']);
            Route::put('pregnancies/{id}', [PregnancyController::class, 'update'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);
            Route::post('pregnancies/{id}/close', [PregnancyController::class, 'close'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);
            Route::delete('pregnancies/{id}', [PregnancyController::class, 'destroy'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);

            // Prenatal screenings (carné perinatal). The catalogue is the MSP
            // schedule — see knowledge/reference/CARNE_PERINATAL.md.
            Route::get('pregnancies/{id}/screenings', [PregnancyController::class, 'screenings'])->whereNumber('id')->middleware(['permission:records.view', 'specialty.capability:gyneco_obstetrics']);
            // La curva del embarazo: un punto por control prenatal, construida
            // desde las consultas enlazadas. Sin banda normal — los percentiles de
            // altura uterina son una tabla publicada y aquí no se dibuja un rango
            // clínico que no se pueda citar.
            Route::get('pregnancies/{id}/timeline', [PregnancyController::class, 'timeline'])->whereNumber('id')->middleware(['permission:records.view', 'specialty.capability:gyneco_obstetrics']);
            Route::post('pregnancies/{id}/screenings', [PregnancyController::class, 'storeScreening'])->whereNumber('id')->middleware(['permission:records.manage', 'specialty.capability:gyneco_obstetrics']);

            // Medical certificates (clinical documents)
            Route::get('patients/{id}/certificates', [MedicalCertificateController::class, 'index'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('patients/{id}/certificates', [MedicalCertificateController::class, 'store'])->whereNumber('id')->middleware('permission:records.manage');
            // The draft as a PDF, not saved: the form shows it beside itself.
            Route::post('patients/{id}/certificates/preview', [MedicalCertificateController::class, 'previewDraft'])->whereNumber('id')->middleware(['permission:records.manage', 'throttle:60,1']);
            Route::get('certificates/{id}', [MedicalCertificateController::class, 'show'])->whereNumber('id')->middleware('permission:records.view');
            Route::put('certificates/{id}', [MedicalCertificateController::class, 'update'])->whereNumber('id')->middleware('permission:records.manage');
            Route::get('certificates/{id}/pdf', [MedicalCertificateController::class, 'pdf'])->whereNumber('id')->middleware('permission:records.view');
            Route::get('certificates/{id}/preview', [MedicalCertificateController::class, 'preview'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('certificates/{id}/duplicate', [MedicalCertificateController::class, 'duplicate'])->whereNumber('id')->middleware('permission:records.manage');

            // Medical records / consultations
            Route::post('records', [MedicalRecordController::class, 'store'])->middleware('permission:records.manage');
            // Triage handoff: nurse opens a draft + records vitals (vitals.create);
            // the doctor completes the clinical record (records.manage).
            Route::post('records/draft', [MedicalRecordController::class, 'draft'])->middleware('permission:vitals.create');
            Route::patch('records/{id}/vitals', [MedicalRecordController::class, 'vitals'])->whereNumber('id')->middleware('permission:vitals.create');
            Route::put('records/{id}', [MedicalRecordController::class, 'update'])->whereNumber('id')->middleware('permission:records.manage');
            Route::get('records/{id}', [MedicalRecordController::class, 'show'])->whereNumber('id')->middleware('permission:records.view');
            // A patient sitting in front of the doctor cannot be left half
            // attended because the trial lapsed mid-consultation. Opening a new
            // one is blocked; closing the open one is not.
            Route::post('records/{id}/close', [MedicalRecordController::class, 'close'])->whereNumber('id')->middleware('permission:records.manage')->withoutMiddleware('workspace.writable');
            // Voiding a visit saved by mistake: author or workspace owner, any day,
            // with a reason. Soft delete — clinical history is never hard-deleted.
            Route::post('records/{id}/void', [MedicalRecordController::class, 'void'])->whereNumber('id')->middleware('permission:records.manage');
            Route::get('records/{id}/prescription', [MedicalRecordController::class, 'prescriptionPdf'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('records/{id}/attachments', [MedicalRecordController::class, 'storeAttachment'])->whereNumber('id')->middleware('permission:records.manage');

            // Lab / imaging orders ("solicitud de exámenes") attached to a consultation
            Route::get('records/{id}/orders', [StudyOrderController::class, 'index'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('records/{id}/orders', [StudyOrderController::class, 'store'])->whereNumber('id')->middleware('permission:records.manage');
            // ...and the same orders reached through the patient, where the
            // consultation is optional: a repeat lab slip asked for by phone, or
            // a study ordered ahead of the appointment it will serve.
            Route::get('patients/{id}/orders', [StudyOrderController::class, 'indexForPatient'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('patients/{id}/orders', [StudyOrderController::class, 'storeForPatient'])->whereNumber('id')->middleware('permission:records.manage');
            Route::get('study-orders/{id}', [StudyOrderController::class, 'show'])->whereNumber('id')->middleware('permission:records.view');
            Route::get('study-orders/{id}/pdf', [StudyOrderController::class, 'pdf'])->whereNumber('id')->middleware('permission:records.view');
            // Whether the study came back. Separate from the order itself: a
            // result arrives days later, often typed by someone who did not
            // issue the order.
            Route::put('study-orders/{id}/result', [StudyOrderController::class, 'updateResult'])->whereNumber('id')->middleware('permission:records.manage');
            Route::delete('study-orders/{id}', [StudyOrderController::class, 'destroy'])->whereNumber('id')->middleware('permission:records.manage');

            // Attachments
            Route::get('attachments/{id}/download', [AttachmentController::class, 'download'])->whereNumber('id')->middleware('permission:records.view');

            // Documents — workspace-wide & patient document library ("Documentos" screen)
            Route::get('documents', [DocumentController::class, 'index'])->middleware('permission:records.view');
            Route::post('documents', [DocumentController::class, 'storeDocument'])->middleware('permission:records.manage');
            Route::get('patients/{id}/documents', [DocumentController::class, 'patientDocuments'])->whereNumber('id')->middleware('permission:records.view');
            Route::post('patients/{id}/documents', [DocumentController::class, 'storePatientDocument'])->whereNumber('id')->middleware('permission:records.manage');

            // Cashier (caja): "por cobrar" list + billing-scoped patient lookup
            Route::get('clinic/billables', [BillingController::class, 'billables'])->middleware('permission:billing.view');
            Route::get('billing/patients', [BillingController::class, 'patientLookup'])->middleware('permission:billing.manage');
            // Attending-doctor picker + default commission % (walk-in / manual invoice).
            Route::get('billing/doctors', [BillingController::class, 'doctors'])->middleware('permission:billing.manage');

            // Billing
            Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:billing.view');
            // Counts per SRI state for the control panel inside Facturación.
            // Declared before `invoices/{id}` for readability; the numeric
            // constraint on that route is what actually keeps them apart.
            Route::get('invoices/sri-summary', [InvoiceController::class, 'sriSummary'])->middleware('permission:billing.view');
            Route::post('invoices', [InvoiceController::class, 'store'])->middleware('permission:billing.manage');
            Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id')->middleware('permission:billing.view');
            Route::get('invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id')->middleware('permission:billing.view');
            // Voiding is a tax obligation, not a feature: a wrong invoice must
            // be annullable even when the subscription lapsed. Same for
            // collecting care already given, and for finishing the SRI cycle of
            // a voucher already filed — the tax authority has it either way, so
            // stopping here would only desynchronize us from them.
            Route::post('invoices/{id}/void', [InvoiceController::class, 'void'])->whereNumber('id')->middleware('permission:billing.manage')->withoutMiddleware('workspace.writable');
            Route::post('invoices/{id}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('id')->middleware('permission:billing.manage')->withoutMiddleware('workspace.writable');
            Route::post('invoices/{id}/payments', [InvoiceController::class, 'storePayment'])->whereNumber('id')->middleware('permission:billing.manage')->withoutMiddleware('workspace.writable');
            Route::post('invoices/{id}/sri/retry', [InvoiceController::class, 'retrySri'])->whereNumber('id')->middleware('permission:billing.manage')->withoutMiddleware('workspace.writable');

            // Payments (collections)
            Route::delete('payments/{id}', [PaymentController::class, 'destroy'])->whereNumber('id')->middleware('permission:billing.manage');

            // Credit notes
            Route::get('credit-notes', [CreditNoteController::class, 'index'])->middleware('permission:billing.view');
            Route::post('credit-notes/{id}/sri/retry', [CreditNoteController::class, 'retrySri'])->whereNumber('id')->middleware('permission:billing.manage')->withoutMiddleware('workspace.writable');

            // AI assistant — clinical Q&A grounded in the active workspace's own
            // data via tool-calling (see AiAssistantService). Same audience as
            // clinical history (records.view), so gated on its own permission.
            // Throttled per-user: each call costs a paid Gemini request plus up
            // to MAX_TOOL_ROUNDS DB round trips (security-auditor recommendation).
            Route::post('assistant/chat', [AiAssistantController::class, 'chat'])
                ->middleware(['permission:assistant.use', 'throttle:20,1']);

            // Elo — proposals inside the atención (ADR-017). Only a clinical picture
            // travels (no patient id, name or cédula); the doctor accepts or rejects.
            Route::post('elo/prescription', [EloController::class, 'prescription'])
                ->middleware(['permission:records.manage', 'permission:assistant.use', 'throttle:20,1']);
            Route::post('elo/note', [EloController::class, 'note'])
                ->middleware(['permission:records.manage', 'permission:assistant.use', 'throttle:20,1']);
            Route::post('elo/dictation', [EloController::class, 'dictation'])
                ->middleware(['permission:records.manage', 'permission:assistant.use', 'throttle:20,1']);
            Route::post('elo/exams', [EloController::class, 'exams'])
                ->middleware(['permission:records.manage', 'permission:assistant.use', 'throttle:20,1']);
            Route::post('elo/events', [EloController::class, 'event'])
                ->middleware(['permission:assistant.use', 'throttle:60,1']);

            // Ajustes → Agentes (ADR-017, part 2): per-doctor switches and rules.
            // {agent} is a fixed catalog key; {rule} is resolved through mine().
            Route::middleware(['permission:assistant.use', 'throttle:60,1'])->group(function () {
                $agents = EloAgents::keys();
                Route::get('elo/agents', [EloRulesController::class, 'agents']);
                Route::put('elo/agents/{agent}', [EloRulesController::class, 'toggle'])->whereIn('agent', $agents);
                Route::get('elo/agents/{agent}/rules', [EloRulesController::class, 'rules'])->whereIn('agent', $agents);
                Route::post('elo/agents/{agent}/rules', [EloRulesController::class, 'store'])->whereIn('agent', $agents);
                Route::patch('elo/rules/{rule}', [EloRulesController::class, 'update'])->whereNumber('rule');
                Route::delete('elo/rules/{rule}', [EloRulesController::class, 'destroy'])->whereNumber('rule');
            });
            Route::post('elo/agents/{agent}/teach', [EloRulesController::class, 'teach'])
                ->whereIn('agent', EloAgents::keys())
                ->middleware(['permission:assistant.use', 'throttle:20,1']);
        });
    });
});
