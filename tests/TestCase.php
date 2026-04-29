<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prime the install gate cache so tests can hit /dashboard, /login, etc.
        // without being redirected to /setup by RedirectIfNotInstalled.
        // Tests that exercise the install flow itself call Cache::forget('app.installed')
        // in their own setUp to undo this.
        Cache::put('app.installed', true);
    }
}
