<?php

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

/*
 * Global test bootstrap — S6/T3
 *
 * TenantScope is fail-closed : unbooted queries return zero rows (see
 * App\Tenant\TenantScope::apply). Most tests in this suite exercise
 * tenant-scoped models and therefore need a booted TenantContext to
 * return any data at all. This beforeEach creates a default Association
 * and boots it so those tests keep working.
 *
 * Security-sensitive tests that verify the fail-closed behavior itself
 * (e.g. tests/Unit/Tenant/TenantScopeTest.php, CrossTenantAccessTest
 * scenario 9) MUST start their own beforeEach with TenantContext::clear()
 * to undo this bootstrap. See AccessControlTest for the pattern.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Boot a default tenant context so that tenant-scoped models work out of the box.
        // Tests that manage their own context (e.g. isolation tests, explicit boot/clear)
        // override this by calling TenantContext::clear() or TenantContext::boot() in
        // their own beforeEach — which runs AFTER this global hook.
        $association = Association::factory()->create();
        TenantContext::boot($association);
    })
    ->afterEach(fn () => TenantContext::clear())
    ->in('Feature', 'Livewire', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
