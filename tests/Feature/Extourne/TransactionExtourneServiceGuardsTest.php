<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;

function guardsActAsRole(RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    return $user;
}

function guardsCreateRecette(?array $overrides = []): Transaction
{
    $tx = Transaction::factory()->create(array_merge([
        'type' => TypeTransaction::Recette,
        'libelle' => 'X',
        'montant_total' => 80,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
    ], $overrides));

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 80,
    ]);

    return $tx;
}

test('refus si Gestionnaire — AuthorizationException', function (): void {
    guardsActAsRole(RoleAssociation::Gestionnaire);
    $tx = guardsCreateRecette();

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx, ExtournePayload::fromOrigine($tx)))
        ->toThrow(AuthorizationException::class);

    expect(Extourne::query()->count())->toBe(0);
    expect($tx->fresh()->extournee_at)->toBeNull();
});

test('refus si Consultation — AuthorizationException', function (): void {
    guardsActAsRole(RoleAssociation::Consultation);
    $tx = guardsCreateRecette();

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx, ExtournePayload::fromOrigine($tx)))
        ->toThrow(AuthorizationException::class);
});

test('refus si transaction dépense', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $tx = guardsCreateRecette(['type' => TypeTransaction::Depense]);

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx, ExtournePayload::fromOrigine($tx)))
        ->toThrow(RuntimeException::class);

    expect(Extourne::query()->count())->toBe(0);
});

test('refus si transaction déjà extournée', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $tx = guardsCreateRecette(['extournee_at' => now()]);

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx, ExtournePayload::fromOrigine($tx)))
        ->toThrow(RuntimeException::class);
});

test('refus si transaction est elle-même une extourne', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $extourne = Extourne::factory()->create();
    $miroir = $extourne->extourne;

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($miroir, ExtournePayload::fromOrigine($miroir)))
        ->toThrow(RuntimeException::class);
});

test('refus si HelloAsso', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $tx = guardsCreateRecette(['helloasso_order_id' => 42]);

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx, ExtournePayload::fromOrigine($tx)))
        ->toThrow(RuntimeException::class);
});

test('refus si transaction portée par facture validée', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $tx = guardsCreateRecette();

    $facture = Facture::create([
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Validee,
        'tiers_id' => Tiers::factory()->create()->id,
        'montant_total' => 0,
        'saisi_par' => auth()->id(),
        'exercice' => 2026,
    ]);
    $facture->transactions()->attach($tx->id);

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx->fresh(), ExtournePayload::fromOrigine($tx->fresh())))
        ->toThrow(RuntimeException::class);
});

test('refus si transaction soft-deleted', function (): void {
    guardsActAsRole(RoleAssociation::Comptable);
    $tx = guardsCreateRecette();
    $tx->delete();

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($tx->fresh(), ExtournePayload::fromOrigine($tx)))
        ->toThrow(RuntimeException::class);
});

test('intrusion via find — scope tenant retourne null (le service n est pas appelé)', function (): void {
    // Tenant B a une transaction
    $tenantB = Association::factory()->create();
    TenantContext::boot($tenantB);
    $txB = guardsCreateRecette();
    $idB = $txB->id;

    // Tenant A boote et tente find
    $tenantA = Association::factory()->create();
    TenantContext::boot($tenantA);

    expect(Transaction::query()->find($idB))->toBeNull();
});

test('intrusion via objet injecté directement — service refuse via ceinture-bretelles association_id', function (): void {
    // Tenant B crée une transaction
    $tenantB = Association::factory()->create();
    TenantContext::boot($tenantB);
    $txB = guardsCreateRecette();

    // Tenant A boote, comptable A
    $tenantA = Association::factory()->create();
    TenantContext::boot($tenantA);
    guardsActAsRole(RoleAssociation::Comptable);

    // Bypass scope pour récupérer l'objet du tenant B malgré tenant A booté
    $txDeBobjet = Transaction::query()
        ->withoutGlobalScope(TenantScope::class)
        ->find($txB->id);

    expect($txDeBobjet)->not->toBeNull();
    expect($txDeBobjet->association_id)->toBe($tenantB->id);

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($txDeBobjet, ExtournePayload::fromOrigine($txDeBobjet)))
        ->toThrow(RuntimeException::class);

    // Aucune Extourne ne doit avoir été créée, même côté tenant B
    expect(Extourne::query()->withoutGlobalScope(TenantScope::class)->count())->toBe(0);

    // L'origine du tenant B reste intacte
    $txBfresh = Transaction::query()
        ->withoutGlobalScope(TenantScope::class)
        ->find($txB->id);
    expect($txBfresh->extournee_at)->toBeNull();
});
