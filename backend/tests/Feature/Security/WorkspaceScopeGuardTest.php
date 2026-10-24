<?php

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * G1 — structural guard against the project's documented risk #1:
 * "Si se olvida BelongsToWorkspace en un nuevo modelo, los datos de todos los
 * workspaces son accesibles."
 *
 * No adversarial test can catch that, because the model it would attack does not
 * exist yet. So instead of testing behaviour, this reads the schema: every model
 * whose table carries a `workspace_id` column must either use the trait, or
 * appear below with a reason. Adding a model without doing one or the other
 * fails the suite.
 *
 * When this test fails, do NOT reach for the exception list first. The default
 * answer is `use BelongsToWorkspace;`. An exception is only correct when the
 * model must be readable from outside any tenant — auth, audit, the SuperAdmin
 * panel, or a shared catalogue.
 *
 * @return array<class-string, string> model => why it is safe unscoped
 */
function deliberatelyUnscoped(): array
{
    return [
        // Resolved from the authenticated user before any workspace is active
        // (login, own profile). A global scope would break authentication.
        App\Models\Doctor::class => 'Resolved by identity, before a tenant exists.',

        // The membership table ActiveWorkspace consults to decide which
        // workspaces the caller may enter. Scoping it would be circular.
        App\Models\WorkspaceMember::class => 'Decides tenant access; cannot depend on it.',

        // Looked up by token by someone who is NOT yet a member of the clinic.
        App\Models\ClinicInvite::class => 'Read by a non-member joining the clinic.',

        // Audit trail. Exists precisely to be read across tenants from the
        // SuperAdmin panel; scoping it would hide what it is for.
        // (AuditLog is absent on purpose: its table carries no workspace_id, so
        // the guard never asks about it.)
        App\Models\AccessLog::class => 'Cross-tenant audit trail (workspace_id nullable).',

        // Commercial state, operated from the SuperAdmin panel outside any
        // tenant. The API never reads these through a workspace context.
        App\Models\WorkspaceEntitlement::class => 'Commercial state, SuperAdmin only.',
        App\Models\WorkspaceEntitlementEvent::class => 'Append-only commercial audit, SuperAdmin only.',
        App\Models\SubscriptionPayment::class => 'Elosar billing, not clinic data, SuperAdmin only.',

        // Shared catalogues: a null workspace_id row is global, a filled one is
        // the clinic's own. A global scope would hide the shared entries, so both
        // filter explicitly in CatalogController (destroyMedication/destroyStudy)
        // and are attacked in CrossTenantAccessTest.
        App\Models\Medication::class => 'Catalogue: null workspace_id means global.',
        App\Models\Study::class => 'Catalogue: null workspace_id means global.',

        // Filtered explicitly by workspace_id in TeamController, and attacked in
        // CrossTenantAccessTest. Kept unscoped because it is also read from the
        // settlement report by workspace, never by ambient tenant.
        App\Models\DoctorCommissionRule::class => 'Filtered explicitly; covered by CrossTenantAccessTest.',
    ];
}

/** Every Eloquent model in app/Models. */
function allModels(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file): string => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

it('scopes every model that carries a workspace_id, or documents why not', function () {
    $unscoped = [];

    foreach (allModels() as $class) {
        $table = (new $class)->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
            continue;
        }

        if (in_array(BelongsToWorkspace::class, class_uses_recursive($class), true)) {
            continue;
        }

        $unscoped[] = $class;
    }

    sort($unscoped);
    $declared = array_keys(deliberatelyUnscoped());
    sort($declared);

    $undeclared = array_diff($unscoped, $declared);
    $stale = array_diff($declared, $unscoped);

    expect($undeclared)->toBe([], sprintf(
        "These models carry a workspace_id but neither use BelongsToWorkspace nor "
        ."declare a reason:\n  %s\n\nAdd `use BelongsToWorkspace;` to the model. Only if it "
        ."genuinely must be readable outside a tenant, add it to deliberatelyUnscoped() "
        ."with a reason — and add an adversarial test for it in CrossTenantAccessTest.",
        implode("\n  ", $undeclared) ?: '(none)'
    ));

    expect($stale)->toBe([], sprintf(
        "These models are listed in deliberatelyUnscoped() but no longer need to be "
        ."(they now use the trait, or lost the column):\n  %s\n\nRemove them from the list "
        ."so it keeps meaning something.",
        implode("\n  ", $stale) ?: '(none)'
    ));
})->group('security');

it('keeps a reason on every declared exception', function () {
    foreach (deliberatelyUnscoped() as $class => $reason) {
        expect(trim($reason))->not->toBe('', "{$class} is exempted without a reason.");
    }
})->group('security');

it('applies the workspace scope to the core clinical and billing models', function () {
    // A belt-and-braces list: if someone ever removed the trait from one of
    // these, the test above would still pass by "declaring" it. These five are
    // the ones that must never become exceptions, whatever the reason.
    $mustBeScoped = [
        App\Models\Patient::class,
        App\Models\MedicalRecord::class,
        App\Models\Appointment::class,
        App\Models\Invoice::class,
        App\Models\MedicalAttachment::class,
    ];

    foreach ($mustBeScoped as $class) {
        // Not expect(...)->toContain(..., $message): Pest's toContain is variadic,
        // so a trailing string is read as another expected value, not a message.
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} MUST stay workspace-scoped.");
    }
})->group('security');
