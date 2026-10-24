<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\ClinicInvite;
use App\Models\Doctor;
use App\Models\GoogleAuthHandoff;
use App\Models\ImpersonationGrant;
use App\Models\StaffProfile;
use App\Models\TermsAcceptance;
use App\Models\User;
use App\Rules\EcuadorianId;
use App\Services\ClinicService;
use App\Services\TrialCampaignService;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use App\Support\PrivateImageUrl;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token-based authentication for the clinic app (Laravel Sanctum).
 *
 * Accounts are either doctors (clinical, own a personal workspace) or staff
 * (receptionist / nurse / billing — clinic members only). Both may obtain a
 * token; pure system administrators (no doctor, no membership) may not — they
 * use the Filament panel instead.
 */
class AuthController extends Controller
{
    /**
     * Fixed valid bcrypt (cost 12) hash compared against when the email is
     * unknown, to keep login timing constant and prevent account enumeration.
     * Not a real credential — no plaintext maps to it in a useful way.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$oFUDeCVUEFcbfuej7VVqmuI7F6zGvIsSUrBToqYJZqt2gR.j.YkVe';

    /**
     * Spelled out because "accept_terms must be accepted" tells a doctor nothing
     * about what to do.
     */
    private const TERMS_MESSAGES = [
        'accept_terms.required' => 'Debes aceptar los términos y la política de privacidad para crear la cuenta.',
        'accept_terms.accepted' => 'Debes aceptar los términos y la política de privacidad para crear la cuenta.',
    ];

    public function __construct(
        private readonly ActiveWorkspace $activeWorkspace,
        private readonly WorkspacePermissionService $permissions,
    ) {}

    /**
     * Authenticate by email/password and issue a personal access token. Returns
     * the token plus the bootstrap payload (user, doctor/staff profile, and the
     * accessible workspaces with role + permissions) so the client can pick an
     * active workspace immediately.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        // Case-insensitive: an address stored as «Andres…@» still signs in as «andres…@».
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($credentials['email']))])->first();

        // Constant-time check: always run a full bcrypt comparison even when the
        // email is unknown (fixed valid cost-12 dummy hash), so response timing
        // can't be used to enumerate which accounts exist.
        $hash = $user?->password ?? self::DUMMY_PASSWORD_HASH;
        $passwordOk = Hash::check($credentials['password'], $hash);

        if (! $user || ! $passwordOk) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son válidas.'],
            ]);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Esta cuenta está suspendida.',
            ], 403);
        }

        // Only reachable with the correct password, so it reveals nothing an
        // attacker does not already hold. `code` is what medico-web keys on to
        // offer resending the link instead of showing a generic failure.
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirma tu correo para entrar. Te enviamos un enlace cuando creaste la cuenta.',
                'code' => 'email_unverified',
            ], 403);
        }

        // ActiveWorkspace::available() resolves through the authenticated user.
        Auth::setUser($user);

        if (! $this->canUseApp($user)) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso a la aplicación.',
            ], 403);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->bootstrapPayload($user),
        ]);
    }

    /**
     * Redeems a /superadmin "Impersonate" one-time code for a real session.
     * The code (not a live bearer token) is what travels in the magic link —
     * it is single-use and expires in 5 minutes, so a link caught in browser
     * history or an access log is worthless afterwards. The actual Sanctum
     * token is only minted here, at redemption time.
     */
    public function exchangeImpersonation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $grant = ImpersonationGrant::where('code', $data['code'])->first();

        if (! $grant || ! $grant->isRedeemable()) {
            return response()->json([
                'message' => 'Este enlace de acceso no es válido o ya expiró.',
            ], 404);
        }

        $grant->update(['used_at' => now()]);

        $target = $grant->target;

        if (! $target || $target->isSuspended()) {
            return response()->json([
                'message' => 'Esta cuenta está suspendida.',
            ], 403);
        }

        Auth::setUser($target);

        $token = $target->createToken('impersonation:'.$grant->actor_user_id, ['*'], now()->addMinutes(30))->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->bootstrapPayload($target),
        ]);
    }

    /**
     * Redeems the one-time code left by the Google OAuth callback
     * (see Http\Controllers\GoogleAuthController).
     *
     * Two outcomes, because a Google identity is not the same thing as an
     * account. If it maps to an existing user, this is a login and returns the
     * same payload as login(). If it does not, no session can be handed out:
     * Google supplies a name and an address, but a Doctor also needs a cédula, a
     * phone, a city and specialties, and nobody but the person can accept the
     * terms. In that case the code stays unredeemed and the sign-up wizard
     * presents it again when it submits.
     */
    public function exchangeGoogle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $handoff = GoogleAuthHandoff::where('code', $data['code'])->first();

        if (! $handoff || ! $handoff->isRedeemable()) {
            return response()->json([
                'message' => 'Este acceso con Google no es válido o ya expiró.',
            ], 404);
        }

        if ($handoff->awaitsRegistration()) {
            return response()->json([
                'requires_registration' => true,
                'email' => $handoff->email,
                'name' => $handoff->name,
            ]);
        }

        // Burned before the gates below, not after: a code that has been presented
        // once should not survive to be presented again, whatever the verdict.
        $handoff->markUsed();

        $user = $handoff->user;

        if (! $user || $user->isSuspended()) {
            return response()->json([
                'message' => 'Esta cuenta está suspendida.',
            ], 403);
        }

        // ActiveWorkspace::available() resolves through the authenticated user.
        Auth::setUser($user);

        if (! $this->canUseApp($user)) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso a la aplicación.',
            ], 403);
        }

        $token = $user->createToken('google')->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->bootstrapPayload($user),
        ]);
    }

    /**
     * Validates a `google_code` presented at sign-up and returns the handoff
     * behind it, or null when the request carries no code (ordinary password
     * sign-up). Throws when the code is unusable.
     */
    private function signupHandoff(?string $code, string $email): ?GoogleAuthHandoff
    {
        if ($code === null) {
            return null;
        }

        $handoff = GoogleAuthHandoff::where('code', $code)->first();

        if (! $handoff || ! $handoff->isRedeemable() || ! $handoff->awaitsRegistration()) {
            throw ValidationException::withMessages([
                'google_code' => ['El acceso con Google no es válido o ya expiró. Vuelve a intentarlo.'],
            ]);
        }

        // The address is the entire value of the handoff. Without this check the
        // form could carry any address at all and still be signed off as
        // "verified by Google", which is how you register somebody else's e-mail.
        if (! hash_equals($handoff->email, $email)) {
            throw ValidationException::withMessages([
                'email' => ['El correo no coincide con la cuenta de Google que autorizaste.'],
            ]);
        }

        return $handoff;
    }

    /**
     * Public lookup of an invitation by token, so the staff registration screen
     * can show who's inviting and to which clinic/role before sign-up. Never
     * leaks beyond the clinic name, email and role.
     */
    public function showInvite(string $token): JsonResponse
    {
        $invite = ClinicInvite::with('workspace')->where('token', $token)->first();

        if (! $invite || ! $invite->isRedeemable()) {
            return response()->json(['message' => 'La invitación no es válida o ya fue utilizada.'], 404);
        }

        return response()->json([
            'data' => [
                'email' => $invite->email,
                'role' => $invite->role,
                'clinic_name' => $invite->workspace?->name,
            ],
        ]);
    }

    /**
     * Register an account from a clinic invitation token. Non-doctor roles create
     * a User (account_type=staff) + StaffProfile — NO Doctor and NO personal
     * workspace. A `doctor` invite instead creates a real Doctor (delegated to
     * registerInvitedDoctor). Either way the new member joins the inviting clinic
     * and is logged straight in.
     */
    public function registerStaff(Request $request, ClinicService $clinics): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'accept_terms' => ['required', 'accepted'],
        ], self::TERMS_MESSAGES);

        $invite = ClinicInvite::with('workspace')->where('token', $data['token'])->first();

        if (! $invite || ! $invite->isRedeemable()) {
            throw ValidationException::withMessages([
                'token' => 'La invitación no es válida o ya fue utilizada.',
            ]);
        }

        if (User::where('email', $invite->email)->exists()) {
            throw ValidationException::withMessages([
                'token' => 'Ya existe una cuenta con este correo electrónico.',
            ]);
        }

        // A doctor invitation needs a real Doctor (medical license, specialty,
        // signature) — the same clinical fields as self-registration.
        if ($invite->role === 'doctor') {
            return $this->registerInvitedDoctor($request, $invite, $data, $clinics);
        }

        $user = DB::transaction(function () use ($invite, $data, $clinics) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invite->email,
                'password' => Hash::make($data['password']),
                'account_type' => 'staff',
            ]);

            // The address is already proven: the invitation token travelled to
            // it, and only someone reading that inbox could be here. Asking them
            // to confirm the same address twice buys nothing and costs a step.
            $user->markEmailAsVerified();
            $this->recordTermsAcceptance($user);

            StaffProfile::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
            ]);

            $clinics->attachMember($invite->workspace, $user->fresh('staffProfile'), $invite->role);
            $invite->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::setUser($user);
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->bootstrapPayload($user),
        ], 201);
    }

    /**
     * Register a DOCTOR account from a `doctor`-role invitation. Creates the User
     * (account_type=doctor) + Doctor — Doctor::booted() auto-creates the personal
     * workspace — then attaches the doctor to the inviting clinic with the
     * `doctor` membership role (full clinical permissions). Validates the same
     * clinical fields as self-registration; email is taken from the invite.
     *
     * @param  array<string, mixed>  $base  Already-validated base fields (name, password).
     */
    private function registerInvitedDoctor(
        Request $request,
        ClinicInvite $invite,
        array $base,
        ClinicService $clinics,
    ): JsonResponse {
        $data = $request->validate([
            'id_number' => ['required', 'string', 'max:10', 'unique:doctors,id_number', EcuadorianId::cedula()],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:255'],
            'specialty_ids' => ['required', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct', 'exists:specialties,id'],
        ]);

        $user = DB::transaction(function () use ($invite, $base, $data, $clinics) {
            $user = User::create([
                'name' => $base['name'],
                'email' => $invite->email,
                'password' => Hash::make($base['password']),
                'account_type' => 'doctor',
            ]);

            // Proven by the invitation itself — see registerStaff().
            $user->markEmailAsVerified();
            $this->recordTermsAcceptance($user);

            // First selected specialty is primary — it also fills the legacy
            // singular specialty_id column (printed on prescriptions, etc.).
            $specialtyIds = $data['specialty_ids'];

            $doctor = Doctor::create([
                'user_id' => $user->id,
                'name' => $base['name'],
                'specialty_id' => $specialtyIds[0],
                'id_number' => $data['id_number'],
                'city' => $data['city'],
                'email' => $invite->email,
                'phone' => $data['phone'],
            ]);

            $doctor->specialties()->sync(
                collect($specialtyIds)->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])
            );

            // Doctor::booted() created the personal workspace; now join the clinic.
            $clinics->attachDoctor($invite->workspace, $doctor, 'doctor');
            $invite->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::setUser($user);
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            ...$this->bootstrapPayload($user),
        ], 201);
    }

    /**
     * Self-service DOCTOR registration. Creates the User (account_type=doctor) +
     * Doctor — Doctor::booted() auto-creates the personal workspace. Returns a
     * token so the new doctor is logged straight in, same shape as login.
     */
    public function register(Request $request, TrialCampaignService $campaigns): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // One or the other: a password, or proof that Google just vouched for
            // this address (see signupHandoff).
            'password' => ['required_without:google_code', 'nullable', 'string', 'min:8'],
            'google_code' => ['required_without:password', 'nullable', 'string'],
            'id_number' => ['required', 'string', 'max:10', 'unique:doctors,id_number', EcuadorianId::cedula()],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:255'],
            'specialty_ids' => ['required', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct', 'exists:specialties,id'],
            'accept_terms' => ['required', 'accepted'],
        ], self::TERMS_MESSAGES);

        $handoff = $this->signupHandoff($data['google_code'] ?? null, $data['email']);

        $user = DB::transaction(function () use ($data, $campaigns, $handoff) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // No password at all on a Google account, rather than a random
                // hash pretending to be one.
                'password' => $handoff ? null : Hash::make($data['password']),
                'google_id' => $handoff?->google_id,
                'account_type' => 'doctor',
            ]);

            if ($handoff) {
                // Google already proved the address answers, which is the whole
                // point of the confirmation e-mail.
                $user->markEmailAsVerified();
                $handoff->markUsed();
            }

            // Issued here, where "this is a public self-service signup" is a
            // fact of the code path rather than something a model has to infer
            // from the request URL. Must precede Doctor::create(), whose
            // lifecycle reads the grant to entitle the personal workspace.
            $campaigns->grantForDoctor($user);

            // First selected specialty is primary — it also fills the legacy
            // singular specialty_id column (printed on prescriptions, etc.).
            $specialtyIds = $data['specialty_ids'];

            $doctor = Doctor::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'specialty_id' => $specialtyIds[0],
                'id_number' => $data['id_number'],
                'city' => $data['city'],
                'email' => $data['email'],
                'phone' => $data['phone'],
            ]);

            $doctor->specialties()->sync(
                collect($specialtyIds)->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])
            );

            return $user;
        });

        $this->recordTermsAcceptance($user);

        // No session yet, on purpose. Handing over a token here would let the
        // account straight into the app and make the verification gate at login
        // decorative — they would simply never log out.
        //
        // The Google path is the exception, and not a loophole: the address is
        // already verified above, so the listener behind this event sends
        // nothing and there is no gate left to respect.
        event(new Registered($user));

        if ($handoff) {
            return response()->json($this->signedInPayload($user), 201);
        }

        return response()->json($this->verificationPendingPayload($user), 201);
    }

    /**
     * Self-service CLINIC-ADMINISTRATOR registration. Creates a non-doctor owner:
     * User (account_type=staff) + StaffProfile + a clinic workspace they own,
     * joined with the `admin` membership role. NO Doctor and NO personal
     * workspace — the administrator manages operations and invites the team
     * (doctors included) but never sees clinical records. Logs straight in.
     */
    public function registerClinicAdmin(
        Request $request,
        ClinicService $clinics,
        TrialCampaignService $campaigns,
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required_without:google_code', 'nullable', 'string', 'min:8'],
            'google_code' => ['required_without:password', 'nullable', 'string'],
            'clinic_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'accept_terms' => ['required', 'accepted'],
        ], self::TERMS_MESSAGES);

        $handoff = $this->signupHandoff($data['google_code'] ?? null, $data['email']);

        $user = DB::transaction(function () use ($data, $clinics, $campaigns, $handoff) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $handoff ? null : Hash::make($data['password']),
                'google_id' => $handoff?->google_id,
                'account_type' => 'staff',
            ]);

            if ($handoff) {
                $user->markEmailAsVerified();
                $handoff->markUsed();
            }

            StaffProfile::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'job_title' => 'Administración',
            ]);

            // The clinic trial is claimed from this grant, so it has to exist
            // before ClinicService provisions the workspace below.
            $campaigns->grantForClinicAdmin($user->fresh('staffProfile'));

            // Pass the user with its fresh staff profile so the membership row
            // captures staff_profile_id (ClinicService::writeMembership reads it).
            $clinics->createClinicForUser($user->fresh('staffProfile'), $data['clinic_name']);

            return $user;
        });

        $this->recordTermsAcceptance($user);

        // Same reasoning as doctor self-registration: no token until the address
        // has answered, unless Google already answered for it.
        event(new Registered($user));

        if ($handoff) {
            return response()->json($this->signedInPayload($user), 201);
        }

        return response()->json($this->verificationPendingPayload($user), 201);
    }

    /**
     * Token + bootstrap for an account that may sign in immediately. Used by the
     * Google sign-up paths, where the address arrives already verified.
     *
     * @return array<string, mixed>
     */
    private function signedInPayload(User $user): array
    {
        // ActiveWorkspace::available() resolves through the authenticated user,
        // and the workspace was only just created by the lifecycle above.
        Auth::setUser($user->fresh());

        return [
            'token' => $user->createToken('google')->plainTextToken,
            ...$this->bootstrapPayload($user),
        ];
    }

    /**
     * Stamps WHICH version of the terms this account agreed to, not merely that
     * it agreed. "They accepted the terms" is worthless evidence if nobody can
     * say which text that was.
     */
    private function recordTermsAcceptance(User $user): void
    {
        $acceptedAt = now();
        $version = (string) config('lopdp.terms_version');

        $user->forceFill([
            'terms_accepted_at' => $acceptedAt,
            'terms_version' => $version,
        ])->save();

        TermsAcceptance::query()->firstOrCreate(
            ['user_id' => $user->id, 'version' => $version],
            ['accepted_at' => $acceptedAt],
        );
    }

    /**
     * Response shape for a self-registration that still has to confirm its
     * address. Carries no token by design; medico-web keys on
     * `requires_verification` to show the "check your inbox" screen.
     *
     * @return array<string, mixed>
     */
    private function verificationPendingPayload(User $user): array
    {
        return [
            'requires_verification' => true,
            'email' => $user->email,
            'message' => 'Tu cuenta fue creada. Confirma tu correo con el enlace que te enviamos para entrar.',
        ];
    }

    /**
     * Revoke the access token used for the current request.
     */
    /**
     * Hand out a fresh token. Kept for tabs still running the build that renewed
     * daily; sessions no longer expire at a fixed date (ADR-021), so the old
     * token is left alive — expiring it threw out every other tab still using
     * it. An impersonation token keeps its short life: it is never renewed.
     */
    public function refresh(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        abort_if(str_starts_with((string) $current->name, 'impersonation:'), 403, 'Esta sesión no se puede renovar.');

        $token = $request->user()->createToken($current->name)->plainTextToken;

        return response()->json(['token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Revoke every token, this one included.
     *
     * The case this exists for is a doctor whose phone was lost or stolen. Until
     * now the only way to evict a session they no longer control was to reset
     * the password, which is a strange thing to demand of someone whose password
     * was never the problem.
     *
     * The current token goes too, on purpose: a caller who wants everything
     * closed means everything, and leaving this one alive would quietly make the
     * device that pressed the button an exception.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $revoked = $request->user()->tokens()->count();

        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Cerramos la sesión en todos los dispositivos. Vuelve a iniciar sesión.',
            'revoked' => $revoked,
        ]);
    }

    /**
     * Return the authenticated account and its accessible workspaces.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Esta cuenta está suspendida.',
            ], 403);
        }

        if (! $this->canUseApp($user)) {
            return response()->json([
                'message' => 'Esta cuenta no tiene acceso a la aplicación.',
            ], 403);
        }

        return response()->json($this->bootstrapPayload($user));
    }

    /** Record an explicit acceptance before restoring the normal API surface. */
    public function acceptTerms(Request $request): JsonResponse
    {
        $request->validate([
            'accept_terms' => ['required', 'accepted'],
        ], self::TERMS_MESSAGES);

        $this->recordTermsAcceptance($request->user());

        return response()->json($this->bootstrapPayload($request->user()->fresh()));
    }

    /**
     * A user can use the app if they are a doctor (personal workspace) or have at
     * least one active clinic membership.
     */
    private function canUseApp(User $user): bool
    {
        return $user->doctor !== null
            || $user->memberships()->where('status', 'active')->exists();
    }

    /**
     * Shared bootstrap payload for login + me.
     *
     * @return array<string, mixed>
     */
    private function bootstrapPayload(User $user): array
    {
        $termsRequired = $user->terms_version !== (string) config('lopdp.terms_version');

        if ($termsRequired) {
            return [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'account_type' => $user->account_type],
                'doctor' => null,
                'staff_profile' => null,
                'workspaces' => [],
                'legal' => [
                    'terms_acceptance_required' => true,
                    'terms_version' => (string) config('lopdp.terms_version'),
                    'password_change_required' => (bool) $user->must_change_password,
                ],
            ];
        }

        $workspaces = $this->permissions->decorate($user, $this->activeWorkspace->available());

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
            ],
            'doctor' => $this->doctorPayload($user),
            'staff_profile' => $this->staffPayload($user),
            'workspaces' => WorkspaceResource::collection($workspaces),
            'legal' => [
                'terms_acceptance_required' => false,
                'terms_version' => (string) config('lopdp.terms_version'),
                'password_change_required' => (bool) $user->must_change_password,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function doctorPayload(User $user): ?array
    {
        $doctor = $user->doctor;

        if (! $doctor) {
            return null;
        }

        return [
            'id' => $doctor->id,
            'name' => $doctor->name,
            'id_number' => $doctor->id_number,
            'email' => $doctor->email,
            'phone' => $doctor->phone,
            'specialty' => $doctor->primarySpecialty()?->name,
            // The web client uses this only to compose the interface. Every
            // clinical endpoint independently evaluates the same capability.
            'clinical_modules' => $doctor->clinicalModules(),
            'photo_url' => PrivateImageUrl::for($doctor->profile_photo_path),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffPayload(User $user): ?array
    {
        $staff = $user->staffProfile;

        if (! $staff) {
            return null;
        }

        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'phone' => $staff->phone,
            'job_title' => $staff->job_title,
        ];
    }
}
