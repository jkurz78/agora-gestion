<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Spec 2026-10-02, § 4bis (D7) et AC-9bis.
 *
 * L'ancienne clé UNIQUE (association_id, tiers_id, exercice) interdisait deux
 * adhésions d'un même tiers sur une même saison dès que l'exercice était renseigné,
 * et comptait les lignes en suppression logique. La nouvelle clé ajoute date_debut et
 * `adhesion_key` (1 pour une ligne vivante, NULL pour une ligne supprimée).
 *
 * ⚠️ Ces tests n'ont de valeur que si la contrainte existe réellement : ils tournent
 * aussi sous SQLite, mais c'est la suite MySQL (`phpunit.mysql.xml`) qui l'éprouve.
 */

function uniciteAdhesion(int $tiersId, ?int $exercice, ?string $debut, ?string $deletedAt = null): int
{
    return (int) DB::table('adhesions')->insertGetId([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiersId,
        'exercice' => $exercice,
        'date_debut' => $debut,
        'date_fin' => $debut !== null ? date('Y-m-d', strtotime($debut.' +1 year -1 day')) : null,
        'mode' => 'duree',
        'deleted_at' => $deletedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->tiers = Tiers::factory()->create();
});

it('AC-9bis · le schéma porte la nouvelle clé et plus l\'ancienne', function (): void {
    $index = collect(Schema::getIndexes('adhesions'))->keyBy('name');

    expect($index->has('adhesions_unique_per_exercice'))->toBeFalse()
        ->and($index->has('adhesions_unique_exercice_debut'))->toBeTrue()
        ->and($index['adhesions_unique_exercice_debut']['unique'])->toBeTrue()
        ->and($index['adhesions_unique_exercice_debut']['columns'])
        ->toBe(['association_id', 'tiers_id', 'exercice', 'date_debut', 'adhesion_key']);
});

it('AC-9bis · deux adhésions du même tiers sur le même exercice, de débuts différents, coexistent', function (): void {
    uniciteAdhesion($this->tiers->id, 2026, '2026-09-15');
    uniciteAdhesion($this->tiers->id, 2026, '2026-12-15');

    expect(DB::table('adhesions')->where('tiers_id', $this->tiers->id)->where('exercice', 2026)->count())->toBe(2);
});

it('AC-9bis · avec la même date de début, la contrainte refuse la seconde', function (): void {
    uniciteAdhesion($this->tiers->id, 2026, '2026-09-01');

    expect(fn () => uniciteAdhesion($this->tiers->id, 2026, '2026-09-01'))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('adhesions')->where('tiers_id', $this->tiers->id)->count())->toBe(1);
});

it('AC-9bis · une ligne en suppression logique ne bloque jamais une ligne vivante (cas Notz)', function (): void {
    // La « Adhésion legacy » de Notz : exercice 2025, supprimée le 21/06/2026.
    uniciteAdhesion($this->tiers->id, 2025, '2025-09-01', '2026-06-21 03:59:51');

    // L'adhésion vivante, reprise à l'exercice 2025 : même clé métier, pas de collision.
    $vivante = uniciteAdhesion($this->tiers->id, 2025, '2025-09-01');

    expect($vivante)->toBeGreaterThan(0)
        ->and(DB::table('adhesions')->where('tiers_id', $this->tiers->id)->count())->toBe(2);
});

it('AC-9bis · plusieurs lignes supprimées de même clé coexistent entre elles', function (): void {
    uniciteAdhesion($this->tiers->id, 2025, '2025-09-01', '2026-06-21 03:59:51');
    uniciteAdhesion($this->tiers->id, 2025, '2025-09-01', '2026-06-21 03:59:51');
    uniciteAdhesion($this->tiers->id, 2025, '2025-09-01');

    expect(DB::table('adhesions')->where('tiers_id', $this->tiers->id)->count())->toBe(3);
});

it('AC-9bis · supprimer logiquement une ligne libère sa clé pour une ligne vivante', function (): void {
    $id = uniciteAdhesion($this->tiers->id, 2026, '2026-09-01');

    DB::table('adhesions')->where('id', $id)->update(['deleted_at' => now()]);

    expect(uniciteAdhesion($this->tiers->id, 2026, '2026-09-01'))->toBeGreaterThan($id);
});

it('AC-9bis · restaurer une ligne dont la clé est reprise par une ligne vivante est refusé', function (): void {
    $supprimee = uniciteAdhesion($this->tiers->id, 2026, '2026-09-01', now()->toDateTimeString());
    uniciteAdhesion($this->tiers->id, 2026, '2026-09-01');

    expect(fn () => DB::table('adhesions')->where('id', $supprimee)->update(['deleted_at' => null]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('AC-9bis · la clé reste propre à un tiers : un autre tiers peut avoir la même saison', function (): void {
    $autre = Tiers::factory()->create();
    uniciteAdhesion($this->tiers->id, 2026, '2026-09-01');
    uniciteAdhesion($autre->id, 2026, '2026-09-01');

    expect(DB::table('adhesions')->where('exercice', 2026)->count())->toBe(2);
});
