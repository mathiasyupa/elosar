<?php

use App\Support\SentryScrubber;
use Sentry\Event;
use Sentry\UserDataBag;

/**
 * A crash report is a data export.
 *
 * Installing an error tracker in a clinical product is not neutral: out of the
 * box it ships request bodies, query strings and user identities to a third
 * party, which here means patient names, cédulas and diagnoses leaving the
 * country under LOPDP. Reading the config and trusting it is not enough — these
 * push real patient data through the scrubber and check what survives.
 */
function eventCarryingPatientData(): Event
{
    $event = Event::createEvent();

    $event->setRequest([
        'url' => 'https://api.elosar.site/api/v1/patients/1044?q=Torres%20Ana',
        'method' => 'POST',
        'query_string' => 'q=Torres%20Ana',
        'cookies' => ['session' => 'abc123'],
        'headers' => ['authorization' => 'Bearer secreto'],
        'data' => [
            'name' => 'Ana Torres',
            'id_number' => '1710034065',
            'diagnosis' => 'Diabetes mellitus tipo 2',
        ],
    ]);

    $user = UserDataBag::createFromUserIdentifier(42);
    $user->setEmail('paciente.ejemplo@example.com');
    $user->setUsername('ANA LUCIA TORRES');
    $user->setIpAddress('190.152.1.1');
    $event->setUser($user);

    $event->setExtra(['patient' => ['name' => 'Ana Torres', 'cedula' => '1710034065']]);

    return $event;
}

it('strips the request body, cookies and headers before anything is sent', function () {
    $scrubbed = SentryScrubber::handle(eventCarryingPatientData());

    $request = $scrubbed->getRequest();

    expect($request)->not->toHaveKey('data')
        ->and($request)->not->toHaveKey('cookies')
        ->and($request)->not->toHaveKey('headers')
        ->and($request)->not->toHaveKey('query_string');

    // Nothing that identifies a patient may appear anywhere in the payload.
    $serialized = json_encode($scrubbed->getRequest()).json_encode($scrubbed->getExtra());

    foreach (['Ana', 'Torres', '1710034065', 'Diabetes', 'secreto', 'abc123'] as $secret) {
        expect($serialized)->not->toContain($secret);
    }
})->group('security');

it('cuts the query string off the url, which carries search terms', function () {
    $scrubbed = SentryScrubber::handle(eventCarryingPatientData());

    expect($scrubbed->getRequest()['url'])->toBe('https://api.elosar.site/api/v1/patients/1044');
})->group('security');

it('keeps the account id but drops the person behind it', function () {
    $scrubbed = SentryScrubber::handle(eventCarryingPatientData());

    $user = $scrubbed->getUser();

    // The id is what makes an error actionable — it finds the account in the
    // panel. The name, the e-mail and the IP add nothing diagnostic.
    expect($user->getId())->toBe(42)
        ->and($user->getEmail())->toBeNull()
        ->and($user->getUsername())->toBeNull()
        ->and($user->getIpAddress())->toBeNull();
})->group('security');

it('drops free-form extra context entirely', function () {
    // Nobody can audit every future call site that attaches context, so none of
    // it travels.
    expect(SentryScrubber::handle(eventCarryingPatientData())->getExtra())->toBe([]);
})->group('security');

it('still reports enough to locate the bug', function () {
    $scrubbed = SentryScrubber::handle(eventCarryingPatientData());

    // Over-scrubbing until reports are useless is its own failure: the route,
    // the verb and the account must survive.
    expect($scrubbed->getRequest()['method'])->toBe('POST')
        ->and($scrubbed->getRequest()['url'])->toContain('/api/v1/patients/')
        ->and($scrubbed->getUser()->getId())->toBe(42);
})->group('security');

it('never sends default PII, whatever the environment says', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        // The parameters ARE the patient data; query text alone names nobody.
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.logs'))->toBeFalse();
})->group('security');

it('keeps the sentry config serializable so config:cache does not break', function () {
    // The Docker entrypoint runs `config:cache`, which fails outright on a
    // config file containing a closure. before_send must stay an array callable.
    $beforeSend = config('sentry.before_send');

    expect($beforeSend)->not->toBeInstanceOf(Closure::class)
        ->and(is_callable($beforeSend))->toBeTrue();
})->group('security');
