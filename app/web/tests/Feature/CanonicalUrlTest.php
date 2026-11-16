<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

afterEach(function () {
    Request::setTrustedHosts([]);
    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

it('redirects guests to the canonical login URL when the proxy forwards the backend host', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://airis.sygris.com',
        'app.trusted_hosts' => ['airis.sygris.com', 'web'],
        'app.enforce_trusted_hosts' => true,
    ]);
    (new AppServiceProvider($this->app))->boot();

    // Next proxies /verify-email/... to the backend's own origin; a signed-in
    // check that fails must send the browser back to the public login page,
    // never to the internal host it reached Laravel on.
    $this->get('http://web/verify-email/1/'.sha1('someone@example.com'))
        ->assertRedirect('https://airis.sygris.com/login');

    expect(url('/dashboard'))->toBe('https://airis.sygris.com/dashboard');
});

it('keeps request-based URLs when APP_URL is not a valid canonical origin', function () {
    $this->app['env'] = 'production';
    // A public host that is not in TRUSTED_HOSTS: CanonicalPublicUrl refuses it.
    config(['app.url' => 'https://public.example.net', 'app.trusted_hosts' => ['web'], 'app.enforce_trusted_hosts' => false]);
    (new AppServiceProvider($this->app))->boot();

    expect(url('/dashboard'))->toBe('http://localhost/dashboard');
});
