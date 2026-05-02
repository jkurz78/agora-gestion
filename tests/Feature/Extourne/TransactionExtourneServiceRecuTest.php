<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Events\TransactionExtournee;
use App\Models\Extourne;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

function extourneActingComptable(): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    return $user;
}

function extourneMakeRecette(StatutReglement $statut, float $montant = 80.0, ?array $overrides = []): Transaction
{
    $tx = Transaction::factory()->create(array_merge([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont mars',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
    ], $overrides));

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    return $tx;
}

test('extourner recette Recu — crée une extourne EnAttente sans lettrage', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);

    $payload = ExtournePayload::fromOrigine($origine);
    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    expect($extourne)->toBeInstanceOf(Extourne::class);
    expect($extourne->rapprochement_lettrage_id)->toBeNull();

    $miroir = $extourne->extourne;
    expect($miroir->type)->toBe(TypeTransaction::Recette);
    expect((float) $miroir->montant_total)->toBe(-80.0);
    expect($miroir->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($miroir->date->isToday())->toBeTrue();
    expect($miroir->libelle)->toBe('Annulation - Cotisation Mr Dupont mars');
    expect($miroir->mode_paiement)->toBe(ModePaiement::Cheque);
    expect($miroir->rapprochement_id)->toBeNull();

    $origine->refresh();
    expect($origine->statut_reglement)->toBe(StatutReglement::Recu);
    expect($origine->extournee_at)->not->toBeNull();
});

test('extourner recette Pointe verrouillée — crée une extourne EnAttente sans lettrage', function (): void {
    extourneActingComptable();
    $rapprochement = RapprochementBancaire::factory()->create();
    $origine = extourneMakeRecette(StatutReglement::Pointe, overrides: [
        'rapprochement_id' => $rapprochement->id,
        'compte_id' => $rapprochement->compte_id,
    ]);

    $payload = ExtournePayload::fromOrigine($origine);
    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    expect($extourne->rapprochement_lettrage_id)->toBeNull();
    expect($extourne->extourne->statut_reglement)->toBe(StatutReglement::EnAttente);

    $origine->refresh();
    expect($origine->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($origine->rapprochement_id)->toBe($rapprochement->id);
});

test('extourner copie tiers, compte, libellé et inverse les lignes', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);
    $payload = ExtournePayload::fromOrigine($origine);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);
    $miroir = $extourne->extourne;

    expect($miroir->tiers_id)->toBe($origine->tiers_id);
    expect($miroir->compte_id)->toBe($origine->compte_id);

    $lignesMiroir = $miroir->lignes()->get();
    $lignesOrigine = $origine->lignes()->get();
    expect($lignesMiroir)->toHaveCount($lignesOrigine->count());
    foreach ($lignesMiroir as $i => $ligneM) {
        expect((float) $ligneM->montant)->toBe(-1 * (float) $lignesOrigine[$i]->montant);
        expect($ligneM->sous_categorie_id)->toBe($lignesOrigine[$i]->sous_categorie_id);
    }
});

test('payload override mode_paiement est appliqué sur l extourne', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);
    $payload = ExtournePayload::fromOrigine($origine, ['mode_paiement' => ModePaiement::Virement]);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    expect($extourne->extourne->mode_paiement)->toBe(ModePaiement::Virement);
});

test('payload notes est copié dans l extourne', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);
    $payload = ExtournePayload::fromOrigine($origine, ['notes' => 'Remboursement chèque émis 30/04']);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    expect($extourne->extourne->notes)->toBe('Remboursement chèque émis 30/04');
});

test('event TransactionExtournee dispatché avec extourne en payload', function (): void {
    Event::fake([TransactionExtournee::class]);
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);
    $payload = ExtournePayload::fromOrigine($origine);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    Event::assertDispatched(TransactionExtournee::class, function (TransactionExtournee $e) use ($extourne) {
        return $e->extourne->id === $extourne->id;
    });
});

test('extourne_n_herite_pas_pieces_jointes_origine', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu, overrides: [
        'piece_jointe_path' => 'transactions/42/justif.pdf',
        'piece_jointe_nom' => 'justif.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->extourne->piece_jointe_path)->toBeNull();
    expect($extourne->extourne->piece_jointe_nom)->toBeNull();
    expect($extourne->extourne->piece_jointe_mime)->toBeNull();

    $origine->refresh();
    expect($origine->piece_jointe_path)->toBe('transactions/42/justif.pdf');
});

test('numero_piece de l extourne est attribué via la séquence courante', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->extourne->numero_piece)->not->toBeNull();
    expect($extourne->extourne->numero_piece)->not->toBe($origine->numero_piece);
});

test('LogContext info log porte tous les ids', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);

    Log::shouldReceive('withContext')->andReturnSelf();
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context = []) use ($origine) {
            return str_contains($message, 'Extourne')
                && isset($context['transaction_origine_id'])
                && $context['transaction_origine_id'] === $origine->id
                && isset($context['transaction_extourne_id'])
                && isset($context['extourne_id']);
        });

    app(TransactionExtourneService::class)->extourner($origine, ExtournePayload::fromOrigine($origine));
});

test('extourner dispatche l event TransactionExtournee à l intérieur d une DB::transaction', function (): void {
    extourneActingComptable();
    $origine = extourneMakeRecette(StatutReglement::Recu);

    $levelInsideListener = null;
    \Event::listen(TransactionExtournee::class, function () use (&$levelInsideListener) {
        $levelInsideListener = DB::transactionLevel();
    });

    app(TransactionExtourneService::class)->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($levelInsideListener)->not->toBeNull();
    expect($levelInsideListener)->toBeGreaterThan(0);
});
