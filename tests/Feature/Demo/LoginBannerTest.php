<?php

declare(strict_types=1);

it('it_shows_demo_banner_on_login_when_demo_is_active', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertSeeText('Démonstration en ligne');
    $response->assertSeeText('admin@demo.fr');
    $response->assertSeeText('jean@demo.fr');
    $response->assertSeeText('demo');
    $response->assertSee('alert alert-info', false);
});

it('it_does_not_show_demo_banner_on_login_when_not_demo', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertDontSeeText('Démonstration en ligne');
});
