<?php

use Illuminate\Support\Facades\Route;

/**
 * G1 — coverage guard for the tenant API surface.
 *
 * A cross-tenant leak needs two things: a route inside the tenant group, and an
 * id in the URL that the caller controls. Routes without a parameter cannot be
 * attacked that way, so this guard deliberately ignores them and watches only
 * the ~57 that can.
 *
 * Every one of those is listed below because someone looked at it and decided it
 * resolves its id through a workspace-scoped model (or through a scoped parent).
 * Add a route with a parameter and this test fails until you do the same. That
 * is the whole point: working alone, nobody else is going to ask.
 *
 * When it fails on a route you just added, the job is not to paste it into the
 * list. It is to answer: if a doctor from another clinic sends this id, what
 * happens? If the answer is not "404", fix the controller first — and if the
 * model behind it has no workspace scope of its own, add the attack to
 * CrossTenantAccessTest before listing it here.
 *
 * @return list<string> "METHOD uri" relative to the api/v1 prefix
 */
function reviewedTenantRoutes(): array
{
    return [
        'DELETE finance-entries/{id}',
        'DELETE immunizations/{id}',
        // The four pregnancies/{id} routes resolve the id with
        // Pregnancy::findOrFail(). Pregnancy is BelongsToWorkspace, so
        // WorkspaceScope turns another clinic's id into a 404 before anything is
        // read or written — the same argument as 'DELETE immunizations/{id}'.
        // Attack covered in PregnancyLifecycleTest ("it returns 404 for a
        // pregnancy of another clinic").
        'DELETE pregnancies/{id}',
        'DELETE medical-services/{id}',
        'DELETE medications/{id}',
        // Patient::findOrFail(), same as `PUT patients/{id}` below — a Patient is
        // BelongsToWorkspace, so WorkspaceScope alone turns another clinic's id
        // into a 404. Pinned by "it refuses to delete a patient from another
        // clinic" in PatientDeletionTest.
        'DELETE patients/{id}',
        'DELETE patients/{id}/allergies/{allergyId}',
        'DELETE patients/{id}/family-background/{backgroundId}',
        'DELETE payments/{id}',
        'DELETE studies/{id}',
        'DELETE study-orders/{id}',
        'GET appointments/{id}',
        'GET attachments/{id}/download',
        'GET certificates/{id}',
        'PUT certificates/{id}',
        'GET certificates/{id}/pdf',
        'GET certificates/{id}/preview',
        'GET clinic/team/{memberId}/commission-rules',
        'GET invoices/{id}',
        'GET invoices/{id}/pdf',
        'GET patients/{id}',
        'GET patients/{id}/baseline-vitals',
        // PatientVitalObservationController resolves the patient through the
        // active workspace before writing; another clinic's id returns 404.
        'POST patients/{id}/vital-observations',
        'GET patients/{id}/certificates',
        'GET patients/{id}/documents',
        'GET patients/{id}/immunizations',
        // Patient::findOrFail() for the two patient-scoped ones; PatientAlert is
        // itself BelongsToWorkspace for the two that take an alert id, so another
        // clinic's id 404s before anything is read or written. Attack covered in
        // PatientAlertTest ("it returns 404 for an alert of another clinic").
        'GET patients/{id}/alerts',
        'POST patients/{id}/alerts',
        'POST patient-alerts/{id}/resolve',
        'DELETE patient-alerts/{id}',
        // Both resolve the patient with Patient::findOrFail(), the same scoped
        // model as the neighbours, so another clinic's id 404s before any
        // gyneco-obstetric data is read or written. Attack covered in
        // GynecoHistoryTest ("it returns 404 for a patient of another clinic").
        'GET patients/{id}/gyneco-history',
        'PUT patients/{id}/gyneco-history',
        // Same Patient::findOrFail() gate; the pregnancy rows are additionally
        // BelongsToWorkspace themselves.
        'GET patients/{id}/pregnancies',
        'POST patients/{id}/pregnancies',
        // See the note on 'DELETE pregnancies/{id}' above.
        'GET pregnancies/{id}',
        'PUT pregnancies/{id}',
        'POST pregnancies/{id}/close',
        // Same Pregnancy::findOrFail() gate; the screenings hang off the scoped
        // pregnancy and carry no id of their own from the client.
        'GET pregnancies/{id}/screenings',
        'POST pregnancies/{id}/screenings',
        // Mismo Pregnancy::findOrFail(); las consultas que lee ya están acotadas
        // por WorkspaceScope al filtrar por el embarazo de esta clínica.
        'GET pregnancies/{id}/timeline',
        // Both order routes resolve the patient with Patient::findOrFail(), the
        // same scoped model as the neighbours above, so another clinic's id is a
        // 404 before any order is read or written. The POST additionally takes an
        // optional medical_record_id, resolved through the equally scoped
        // MedicalRecord and then checked to belong to that same patient — a
        // consultation from another chart is a 422, not a silent mislink. Pinned
        // by "no deja pedir exámenes para un paciente de otra clínica" and
        // "rechaza una consulta de otro paciente" in StudyOrderWithoutConsultationTest.
        'GET patients/{id}/orders',
        'GET patients/{id}/records',
        'GET records/{id}',
        'GET records/{id}/orders',
        'GET records/{id}/prescription',
        'GET study-orders/{id}',
        'GET study-orders/{id}/pdf',
        'PATCH records/{id}/vitals',
        'POST appointments/{id}/cancel',
        'POST appointments/{id}/check-in',
        'POST appointments/{id}/complete',
        'POST appointments/{id}/mark-reminded',
        'POST appointments/{id}/send-confirmation-email',
        'POST appointments/{id}/send-reminder-email',
        'POST certificates/{id}/duplicate',
        'POST credit-notes/{id}/sri/retry',
        'POST invoices/{id}/cancel',
        'POST invoices/{id}/payments',
        'POST invoices/{id}/sri/retry',
        'POST invoices/{id}/void',
        'POST patients/{id}/allergies',
        'POST patients/{id}/certificates',
        // Same Patient::findOrFail() as above; nothing is saved.
        // MedicalCertificateDraftPreviewTest pins the 404.
        'POST patients/{id}/certificates/preview',
        'POST patients/{id}/documents',
        // Resolved through StudyOrder, which is workspace-scoped, so an order
        // from another clinic 404s. Attack covered in StudyOrderResultTest
        // ("it hides an order from another clinic").
        'PUT study-orders/{id}/result',
        'POST patients/{id}/family-background',
        'POST patients/{id}/greeting-email',
        'POST patients/{id}/immunizations',
        // See the note on 'GET patients/{id}/orders' above.
        'POST patients/{id}/orders',
        'POST records/{id}/attachments',
        'POST records/{id}/close',
        'POST records/{id}/void', // MedicalRecord is workspace-scoped; ConsultationVoidTest pins the 404.
        // {document} is not an id: whereIn() limits it to prescription /
        // certificate / order, and the preview always draws the ACTIVE
        // workspace with an in-memory fictional patient — there is nothing of
        // another clinic to reach. DocumentPaperTest pins the 404 for anything else.
        'POST settings/letterhead/preview/{document}',
        // Elo (ADR-017). {agent} is not an id: whereIn() limits it to the fixed
        // catalog. {rule} is an EloRule (BelongsToWorkspace) fetched through
        // mine(), so another clinic's or another doctor's rule 404s —
        // EloRulesTest pins both.
        'PUT elo/agents/{agent}',
        'GET elo/agents/{agent}/rules',
        'POST elo/agents/{agent}/rules',
        'POST elo/agents/{agent}/teach',
        'PATCH elo/rules/{rule}',
        'DELETE elo/rules/{rule}',
        'POST records/{id}/orders',
        'PUT appointments/{id}',
        'PUT clinic/team/{memberId}',
        'PUT clinic/team/{memberId}/commission-rules',
        'PUT finance-entries/{id}',
        'PUT medical-services/{id}',
        'PUT patients/{id}',
        'PUT records/{id}',
    ];
}

/**
 * Registered routes inside the tenant group that accept a caller-supplied id.
 *
 * @return list<string>
 */
function attackableTenantRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        // `workspace` (ResolveActiveWorkspace) is what makes a route tenant-bound.
        if (! in_array('workspace', $route->gatherMiddleware(), true)) {
            continue;
        }

        $uri = preg_replace('#^api/v1/#', '', $route->uri());

        if (! str_contains($uri, '{')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $found[] = "{$method} {$uri}";
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

it('has reviewed every tenant route that accepts an id', function () {
    $actual = attackableTenantRoutes();
    $reviewed = reviewedTenantRoutes();
    sort($reviewed);

    $unreviewed = array_diff($actual, $reviewed);

    expect($unreviewed)->toBe([], sprintf(
        "New tenant route(s) accepting a caller-supplied id, not yet reviewed for "
        ."cross-tenant access:\n  %s\n\nFor each one, answer: if a doctor from another "
        ."clinic sends this id, does it return 404? Resolve the id through a "
        ."workspace-scoped model (or a scoped parent), add the attack to "
        ."CrossTenantAccessTest if the model has no scope of its own, then add the "
        ."route to reviewedTenantRoutes().",
        implode("\n  ", $unreviewed) ?: '(none)'
    ));
})->group('security');

it('keeps the reviewed list free of routes that no longer exist', function () {
    $stale = array_diff(reviewedTenantRoutes(), attackableTenantRoutes());

    expect($stale)->toBe([], sprintf(
        "These routes are in reviewedTenantRoutes() but are no longer registered:\n  %s\n\n"
        ."Remove them, so the list keeps describing the real surface.",
        implode("\n  ", $stale) ?: '(none)'
    ));
})->group('security');

it('binds every attackable route to the tenant resolver', function () {
    // Sanity check on the guard itself: if `workspace` middleware were renamed or
    // dropped from the group, attackableTenantRoutes() would quietly return an
    // empty set and both tests above would pass while checking nothing.
    expect(attackableTenantRoutes())->not->toBeEmpty();
})->group('security');
