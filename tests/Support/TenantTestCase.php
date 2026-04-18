<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Tests\TestCase;

abstract class TenantTestCase extends TestCase
{
    protected Association $association;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->association = Association::factory()->create();
        $this->adminUser = User::factory()->create();
        $this->adminUser->associations()->attach($this->association->id, [
            'role' => 'admin',
            'joined_at' => now(),
        ]);
        $this->adminUser->update(['derniere_association_id' => $this->association->id]);

        TenantContext::boot($this->association);
        session(['current_association_id' => $this->association->id]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    protected function actingAsAdmin(): self
    {
        $this->actingAs($this->adminUser);

        return $this;
    }
}
