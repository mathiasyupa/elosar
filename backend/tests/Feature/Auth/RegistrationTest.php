<?php

use App\Models\User;

/*
| There is no web registration route. Breeze's original one created a bare User
| with no account_type, no Doctor and therefore no workspace, trial grant or
| entitlement — accounts that exist without belonging anywhere. It also carried
| no throttle whatsoever.
|
| Registration is the API's job: POST /api/v1/auth/register, which validates the
| Ecuadorian id, links specialties and provisions the trial. These tests keep the
| web door shut.
*/

test('the web registration screen no longer exists', function () {
    $this->get('/register')->assertNotFound();
});

test('the web registration endpoint no longer accepts accounts', function () {
    $before = User::count();

    $this->post('/register', [
        'name' => 'Intruso',
        'email' => 'intruso@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::count())->toBe($before);
});

test('no route is named register', function () {
    // welcome.blade.php renders a sign-up link behind @if (Route::has('register')).
    // Keeping the name unregistered is what makes that link disappear instead of
    // throwing, so the marketing page never offers a door that is bricked up.
    expect(Illuminate\Support\Facades\Route::has('register'))->toBeFalse();
});
