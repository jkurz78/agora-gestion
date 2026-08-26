<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\RapportBilan;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    session(['exercice_actif' => 2025]);
    $this->utilisateurBilanEcran = User::factory()->create();
});

afterEach(function (): void {
    session()->forget('exercice_actif');
});

function ajouterLigneBilanEcran(
    string $numero,
    string $intitule,
    int $debitCentimes,
    int $creditCentimes,
    string $date = '2025-10-15',
): void {
    $compte = Compte::query()->firstOrCreate(['numero_pcg' => $numero], [
        'association_id' => TenantContext::currentId(),
        'intitule' => $intitule,
        'classe' => (int) $numero[0],
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);

    $transaction = Transaction::query()->create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Virement,
        'date' => $date,
        'libelle' => 'Fixture écran bilan',
        'montant_total' => '0.00',
    ]);

    TransactionLigne::query()->create([
        'transaction_id' => (int) $transaction->id,
        'compte_id' => (int) $compte->id,
        'debit' => centimesBilanEcran($debitCentimes),
        'credit' => centimesBilanEcran($creditCentimes),
        'montant' => '0.00',
        'libelle' => 'Fixture écran bilan',
    ]);
}

function centimesBilanEcran(int $centimes): string
{
    return intdiv($centimes, 100).'.'.str_pad((string) ($centimes % 100), 2, '0', STR_PAD_LEFT);
}

it('expose le bilan dans les routes rapports', function (): void {
    $this->actingAs($this->utilisateurBilanEcran)
        ->get(route('rapports.bilan'))
        ->assertOk()
        ->assertSeeLivewire(RapportBilan::class);
});

it('affiche les sections actif et passif ainsi que le bandeau provisoire sur un bilan équilibré', function (): void {
    ajouterLigneBilanEcran('512', 'Banque', 10000, 0);
    ajouterLigneBilanEcran('102', 'Fonds associatifs', 0, 10000);

    Livewire::test(RapportBilan::class)
        ->assertOk()
        ->assertSee('ACTIF')
        ->assertSee('PASSIF')
        ->assertSee('Disponibilités')
        ->assertSee('Fonds propres')
        ->assertSee('Bilan provisoire avant clôture')
        ->assertSee('Bilan équilibré');
});

it('masque intégralement les données de l exercice N moins 1 lorsque le toggle est désactivé', function (): void {
    ajouterLigneBilanEcran('512', 'Banque', 10000, 0);
    ajouterLigneBilanEcran('102', 'Fonds associatifs', 0, 10000);
    ajouterLigneBilanEcran('512', 'Banque', 5000, 0, '2024-10-15');
    ajouterLigneBilanEcran('102', 'Fonds associatifs', 0, 5000, '2024-10-15');

    Livewire::test(RapportBilan::class)
        ->assertSet('compareN1', true)
        ->assertSee('Afficher l’exercice N-1')
        ->assertSee('2024-2025')
        ->assertSeeHtml('data-sort="5000"')
        ->set('compareN1', false)
        ->assertSet('compareN1', false)
        ->assertDontSee('2024-2025')
        ->assertDontSeeHtml('data-sort="5000"');
});

it('lit le toggle N moins 1 depuis le paramètre URL n1', function (): void {
    Livewire::withQueryParams(['n1' => '0'])
        ->test(RapportBilan::class)
        ->assertSet('compareN1', false);
});

it('signale un écart entre actif et passif', function (): void {
    ajouterLigneBilanEcran('512', 'Banque', 10000, 0);

    Livewire::test(RapportBilan::class)
        ->assertSee('Bilan déséquilibré')
        ->assertSee('Écart actif/passif');
});

it('propage l exercice et N moins 1 dans l URL de l export PDF', function (): void {
    $url = Livewire::test(RapportBilan::class)
        ->set('compareN1', false)
        ->instance()
        ->exportUrl('pdf');

    expect($url)
        ->toContain('/rapports/export/bilan/pdf')
        ->toContain('exercice=2025')
        ->toContain('n1=0');
});
