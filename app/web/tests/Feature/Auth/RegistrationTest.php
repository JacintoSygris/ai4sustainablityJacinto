<?php

use App\Support\RegistrationGuard;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('registration screen keeps product copy', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
    $response->assertSee('Te damos la bienvenida a Airis');
    $response->assertDontSee('Laravel');
});

test('laravel register alias renders csrf form without replacing canonical route name', function () {
    $response = $this->get('/laravel/register');

    $response->assertStatus(200);
    $response->assertSee('name="_token"', false);
    expect(route('register', absolute: false))->toBe('/register');
});

test('registration validation errors are Spanish and product neutral', function () {
    config(['app.locale' => 'es']);

    $response = $this->from('/register')->post('/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors(['name', 'email', 'password']);

    $messages = implode(' ', session('errors')->getBag('default')->all());

    expect($messages)->toContain('nombre');
    expect($messages)->toContain('correo electrónico');
    expect($messages)->toContain('confirmación');
    expect($messages)->not->toContain('field');
    expect($messages)->not->toContain('Laravel');
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

test('new users can register using the laravel register alias', function () {
    $response = $this->post('/laravel/register', [
        'name' => 'Alias Test User',
        'email' => 'alias-test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login');
    $this->assertDatabaseHas('users', ['email' => 'alias-test@example.com']);
});

test('the register-config endpoint is public and reports guard state', function () {
    config([
        'services.auth_hardening.require_email_verification' => true,
        'services.auth_hardening.honeypot_field' => 'company_website',
    ]);

    $this->getJson('/api/auth/register-config')
        ->assertOk()
        ->assertJsonPath('data.registration_enabled', true)
        ->assertJsonMissingPath('data.turnstile_site_key')
        ->assertJsonPath('data.require_email_verification', true)
        ->assertJsonPath('data.honeypot_field', 'company_website');
});

test('a filled honeypot silently blocks registration', function () {
    config(['services.auth_hardening.honeypot_field' => 'company_website']);

    $this->post('/register', [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'company_website' => 'http://spam.example',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(\App\Models\User::where('email', 'bot@example.com')->exists())->toBeFalse();
});

test('registration is rate limited per ip', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', [
            'name' => "User $i",
            'email' => "rl$i@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        auth()->logout();
    }

    $this->post('/register', [
        'name' => 'Over Limit',
        'email' => 'overlimit@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(\App\Models\User::where('email', 'overlimit@example.com')->exists())->toBeFalse();
});

test('when verification is enforced registration remains unauthenticated pending email delivery', function () {
    config(['services.auth_hardening.require_email_verification' => true]);

    $this->post('/register', [
        'name' => 'Unverified',
        'email' => 'unverified@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/login');

    $this->assertGuest();
    $this->assertDatabaseHas('users', [
        'email' => 'unverified@example.com',
        'email_verified_at' => null,
    ]);
    $this->getJson('/api/characterization/options')->assertUnauthorized();
});

test('session reports verified true for a verified user', function () {
    $user = \App\Models\User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->getJson('/api/auth/session')
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true);
});

test('public registration fails closed when it is disabled', function () {
    config(['services.auth_hardening.public_registration_enabled' => false]);

    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Disabled Registration',
        'email' => 'disabled@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertDatabaseMissing('users', ['email' => 'disabled@example.test']);
});

test('production registration requires only verified delivery prerequisites', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://example.com',
        'app.trusted_hosts' => ['example.com'],
        'services.auth_hardening.public_registration_enabled' => true,
        'services.auth_hardening.require_email_verification' => true,
        'queue.default' => 'database',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp.url' => null,
    ]);

    expect(RegistrationGuard::registrationUnavailableReasons())->toBe([]);

    config(['mail.default' => 'log']);
    expect(RegistrationGuard::registrationUnavailableReasons())->toBe(['MAIL_MAILER']);
});

test('production registration rejects unsafe nested delivery fallbacks', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://example.com',
        'app.trusted_hosts' => ['example.com'],
        'services.auth_hardening.public_registration_enabled' => true,
        'services.auth_hardening.require_email_verification' => true,
        'queue.default' => 'failover',
        'queue.connections.failover' => [
            'driver' => 'failover',
            'connections' => ['database', 'deferred'],
        ],
        'mail.default' => 'failover',
        'mail.mailers.failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],
    ]);

    expect(RegistrationGuard::registrationAvailable())->toBeFalse();
});

test('production registration rejects an invalid canonical origin before accepting accounts', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'http://example.com/path',
        'app.trusted_hosts' => ['example.com'],
        'services.auth_hardening.public_registration_enabled' => true,
        'services.auth_hardening.require_email_verification' => true,
        'queue.default' => 'database',
        'mail.default' => 'smtp',
    ]);

    expect(RegistrationGuard::registrationAvailable())->toBeFalse();
    $this->get('https://example.com/register')
        ->assertNotFound();
});

test('the deployed Laravel registration form renders the configured abuse controls', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://example.com',
        'app.trusted_hosts' => ['example.com'],
        'app.enforce_trusted_hosts' => false,
        'services.auth_hardening.public_registration_enabled' => true,
        'services.auth_hardening.require_email_verification' => true,
        'services.auth_hardening.honeypot_field' => 'company_website',
        'queue.default' => 'database',
        'mail.default' => 'smtp',
    ]);

    $this->get('https://example.com/register')
        ->assertOk()
        ->assertSee('name="company_website"', false)
        ->assertDontSee('turnstile', false)
        ->assertDontSee('challenges.cloudflare.com', false);
});

test('registration reports an email that already has an account', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $existing = \App\Models\User::factory()->create(['email' => 'private@example.test']);

    $response = $this->from('/register')->post('/register', [
        'name' => 'Existing Person',
        'email' => $existing->email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Ya existe una cuenta');
    $this->assertGuest();
    expect(\App\Models\User::where('email', $existing->email)->count())->toBe(1);
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});
