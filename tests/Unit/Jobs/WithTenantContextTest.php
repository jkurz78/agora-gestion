<?php

declare(strict_types=1);

use App\Jobs\Concerns\WithTenantContext;
use App\Models\Association;
use App\Tenant\TenantContext;

it('boots TenantContext before running the inner callable and clears after', function () {
    $asso = Association::factory()->create();
    $observedId = null;

    $job = new class($asso->id, function () use (&$observedId) {
        $observedId = TenantContext::currentId();
    }) {
        use WithTenantContext;

        public function __construct(public int $associationId, public Closure $work) {}

        public function handle(): void
        {
            $this->runWithTenantContext(fn () => ($this->work)());
        }
    };

    TenantContext::clear();
    $job->handle();

    expect($observedId)->toBe($asso->id);
    expect(TenantContext::currentId())->toBeNull();
});

it('clears TenantContext even when the job throws', function () {
    $asso = Association::factory()->create();

    $job = new class($asso->id)
    {
        use WithTenantContext;

        public function __construct(public int $associationId) {}

        public function handle(): void
        {
            $this->runWithTenantContext(function () {
                throw new RuntimeException('boom');
            });
        }
    };

    TenantContext::clear();

    try {
        $job->handle();
    } catch (RuntimeException) {
        // expected
    }

    expect(TenantContext::currentId())->toBeNull();
});
