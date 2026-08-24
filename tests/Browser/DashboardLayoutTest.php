<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

test('mobile dashboard shows a hamburger toggle and reachable navigation', function () {
    $user = User::factory()->create([
        'email' => 'mobile-layout-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/signin')
            ->resize(390, 844)
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/user', 10)
            ->assertPathIs('/user');

        $browser->assertVisible('[data-flux-sidebar-toggle]');

        $browser->script("document.querySelector('[data-flux-sidebar-toggle]').click()");

        $browser->waitFor('a[href$="/security/two-factor"]')
            ->assertVisible('a[href$="/security/two-factor"]');
    });
});

test('desktop dashboard shows the sidebar and hides the mobile hamburger toggle', function () {
    $user = User::factory()->create([
        'email' => 'desktop-layout-'.Str::random().'@example.com',
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/signin')
            ->resize(1280, 720)
            ->waitFor('input[name="email"]')
            ->type('input[name="email"]', $user->email)
            ->type('input[name="password"]', 'password')
            ->waitFor('button[type="submit"]:enabled')
            ->press('Sign in')
            ->waitForLocation('/user', 10)
            ->assertPathIs('/user');

        $browser->assertMissing('[data-flux-sidebar-toggle]')
            ->assertVisible('[data-flux-sidebar]')
            ->assertSee('Home')
            ->assertSee('Security');
    });
});
