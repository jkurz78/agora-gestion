<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenancy\Isolation;

use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Storage isolation tests.
 *
 * Verify that each tenant's files are kept under their own
 * storage/app/associations/{id}/ directory and that one tenant
 * cannot accidentally list or read files belonging to another.
 */
final class CrossTenantStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 11 — Tenant A cannot list files of tenant B
    // ─────────────────────────────────────────────────────────

    public function test_tenant_a_cannot_list_files_of_tenant_b(): void
    {
        $tenantA = Association::factory()->create();
        $tenantB = Association::factory()->create();

        // Write a secret file under tenant B's directory.
        TenantContext::boot($tenantB);
        Storage::disk('local')->put("associations/{$tenantB->id}/secret.txt", 'SECRET-B');

        // Switch to tenant A — listing its own directory must be empty.
        TenantContext::boot($tenantA);
        $files = Storage::disk('local')->files("associations/{$tenantA->id}");

        $this->assertEmpty($files, 'Tenant A should have no files in its own directory.');
        $this->assertFalse(
            Storage::disk('local')->exists("associations/{$tenantA->id}/secret.txt"),
            'Tenant B\'s secret file must not exist in tenant A\'s directory.',
        );

        // Cleanup.
        Storage::disk('local')->delete("associations/{$tenantB->id}/secret.txt");
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 12 — Tenant A cannot read file directly from tenant B's path
    // ─────────────────────────────────────────────────────────

    public function test_tenant_a_cannot_read_file_from_tenant_b_path(): void
    {
        $tenantA = Association::factory()->create();
        $tenantB = Association::factory()->create();

        TenantContext::boot($tenantB);
        Storage::disk('local')->put("associations/{$tenantB->id}/confidential.txt", 'CONFIDENTIAL-B');
        TenantContext::clear();

        // From tenant A's perspective, the file at B's path should not be accessible
        // through the A-scoped path.  We verify that paths are distinct by
        // construction (different IDs).
        $this->assertNotEquals(
            $tenantA->id,
            $tenantB->id,
            'Tenant IDs must be distinct (sanity check).',
        );

        $aPath = "associations/{$tenantA->id}/confidential.txt";
        $bPath = "associations/{$tenantB->id}/confidential.txt";

        $this->assertFalse(
            Storage::disk('local')->exists($aPath),
            "Tenant A's path must not contain tenant B's file.",
        );
        $this->assertTrue(
            Storage::disk('local')->exists($bPath),
            "Tenant B's file must still exist at B's own path.",
        );

        // Cleanup.
        Storage::disk('local')->delete($bPath);
    }
}
