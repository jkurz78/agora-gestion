<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Livewire\Portail\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Portail\NoteDeFrais\NoteDeFraisService;
use App\Tenant\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Helper : crée une NDF Payée (validee + transaction Recu)
// ---------------------------------------------------------------------------

function makePayeeNdf(Association $asso, Tiers $tiers): NoteDeFrais
{
    $transaction = Transaction::factory()->create([
        'association_id' => $asso->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    return NoteDeFrais::factory()->validee()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $transaction->id,
    ]);
}

// ---------------------------------------------------------------------------
// 1. Service : archive une NDF Payée → archived_at renseigné
// ---------------------------------------------------------------------------

it('archive service: archive une NDF Payée → archived_at renseigné', function () {
    $ndf = makePayeeNdf($this->asso, $this->tiers);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Payee);

    (new NoteDeFraisService)->archive($ndf);

    expect($ndf->fresh()->archived_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. Service : archive une NDF Rejetée → archived_at renseigné
// ---------------------------------------------------------------------------

it('archive service: archive une NDF Rejetée → archived_at renseigné', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    (new NoteDeFraisService)->archive($ndf);

    expect($ndf->fresh()->archived_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// 3. Service : archive une NDF Brouillon → DomainException
// ---------------------------------------------------------------------------

it('archive service: archive une NDF Brouillon → DomainException', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(fn () => (new NoteDeFraisService)->archive($ndf))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 4. Service : archive une NDF Soumise → DomainException
// ---------------------------------------------------------------------------

it('archive service: archive une NDF Soumise → DomainException', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(fn () => (new NoteDeFraisService)->archive($ndf))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 5. Service : archive une NDF Validée (non payée, pas de transaction) → DomainException
// ---------------------------------------------------------------------------

it('archive service: archive une NDF Validée non payée → DomainException', function () {
    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => null,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Validee);

    expect(fn () => (new NoteDeFraisService)->archive($ndf))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 6. Service : archive NDF d'un autre Tiers → policy refuse (403)
// ---------------------------------------------------------------------------

it('archive policy: NDF d\'un autre tiers → policy retourne false', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = makePayeeNdf($this->asso, $autreTiers);

    expect(Gate::forUser($this->tiers)->denies('archive', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 7. Service : archive une NDF déjà archivée → DomainException
// ---------------------------------------------------------------------------

it('archive service: archive une NDF déjà archivée → DomainException', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(fn () => (new NoteDeFraisService)->archive($ndf))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// 8. Accessor : statut Payee quand Validee + Transaction Recu
// ---------------------------------------------------------------------------

it('accessor: statut retourne Payee quand Validee + Transaction Recu', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->asso->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);

    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => $transaction->id,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Payee);
});

// ---------------------------------------------------------------------------
// 9. Accessor : statut Payee quand Validee + Transaction Pointe
// ---------------------------------------------------------------------------

it('accessor: statut retourne Payee quand Validee + Transaction Pointe', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->asso->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);

    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => $transaction->id,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Payee);
});

// ---------------------------------------------------------------------------
// 10. Accessor : statut Validee quand Validee + Transaction EnAttente
// ---------------------------------------------------------------------------

it('accessor: statut retourne Validee quand Validee + Transaction EnAttente', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->asso->id,
        'statut_reglement' => StatutReglement::EnAttente,
    ]);

    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => $transaction->id,
    ]);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Validee);
});

// ---------------------------------------------------------------------------
// 11. Index : onglet Actives (défaut) affiche seulement archived_at IS NULL
// ---------------------------------------------------------------------------

it('index: onglet Actives affiche seulement les NDF non archivées', function () {
    NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF active',
    ]);

    NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF archivée',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais?onglet=actives")
        ->assertStatus(200)
        ->assertSeeText('NDF active')
        ->assertDontSeeText('NDF archivée');
});

// ---------------------------------------------------------------------------
// 12. Index : onglet Archivées affiche seulement archived_at IS NOT NULL
// ---------------------------------------------------------------------------

it('index: onglet Archivées affiche seulement les NDF archivées', function () {
    NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF active',
    ]);

    NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF archivée',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais?onglet=archivees")
        ->assertStatus(200)
        ->assertDontSeeText('NDF active')
        ->assertSeeText('NDF archivée');
});

// ---------------------------------------------------------------------------
// 13. Index : onglet Toutes affiche les deux
// ---------------------------------------------------------------------------

it('index: onglet Toutes affiche actives et archivées', function () {
    NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF active unique',
    ]);

    NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF archivée unique',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais?onglet=toutes")
        ->assertStatus(200)
        ->assertSeeText('NDF active unique')
        ->assertSeeText('NDF archivée unique');
});

// ---------------------------------------------------------------------------
// 14. Show : bouton Archiver visible sur NDF Payée non archivée
// ---------------------------------------------------------------------------

it('show: bouton Archiver visible sur NDF Payée non archivée', function () {
    $ndf = makePayeeNdf($this->asso, $this->tiers);

    expect($ndf->statut)->toBe(StatutNoteDeFrais::Payee);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Archiver');
});

// ---------------------------------------------------------------------------
// 15. Show : bouton Archiver visible sur NDF Rejetée non archivée
// ---------------------------------------------------------------------------

it('show: bouton Archiver visible sur NDF Rejetée non archivée', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSeeText('Archiver');
});

// ---------------------------------------------------------------------------
// 16. Show : bouton Archiver invisible sur NDF Brouillon/Soumise/Validée
// ---------------------------------------------------------------------------

it('show: bouton Archiver invisible sur NDF Brouillon', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertDontSee('modalArchiver');
});

it('show: bouton Archiver invisible sur NDF Soumise', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertDontSee('modalArchiver');
});

it('show: bouton Archiver invisible sur NDF Validée (non payée)', function () {
    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => null,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertDontSee('modalArchiver');
});

// ---------------------------------------------------------------------------
// 17. Show : NDF archivée affichée en lecture seule (aucun bouton action)
// ---------------------------------------------------------------------------

it('show: NDF archivée est en lecture seule — aucun bouton Modifier/Supprimer/Archiver', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSee('Archivée')
        ->assertDontSee('Modifier')
        ->assertDontSee('Supprimer')
        ->assertDontSee('modalArchiver')
        ->assertDontSee('modalSupprimer');
});

// ---------------------------------------------------------------------------
// 18. Policy archive : tiers propriétaire peut archiver NDF Payée
// ---------------------------------------------------------------------------

it('policy archive: tiers propriétaire peut archiver NDF Payée', function () {
    $ndf = makePayeeNdf($this->asso, $this->tiers);

    expect(Gate::forUser($this->tiers)->allows('archive', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 19. Policy archive : tiers propriétaire peut archiver NDF Rejetée
// ---------------------------------------------------------------------------

it('policy archive: tiers propriétaire peut archiver NDF Rejetée', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->allows('archive', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 20. Policy archive : NDF déjà archivée → refus
// ---------------------------------------------------------------------------

it('policy archive: NDF déjà archivée → policy retourne false', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('archive', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 21. Policy archive : NDF Brouillon → refus
// ---------------------------------------------------------------------------

it('policy archive: NDF Brouillon → policy retourne false', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('archive', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 22. Policy update : NDF Rejetée archivée → refus (lecture seule)
// ---------------------------------------------------------------------------

it('policy update: NDF Rejetée archivée → lecture seule, policy retourne false', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('update', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 23. Policy delete : NDF Rejetée archivée → refus (lecture seule)
// ---------------------------------------------------------------------------

it('policy delete: NDF Rejetée archivée → lecture seule, policy retourne false', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->archived()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    expect(Gate::forUser($this->tiers)->denies('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 24. Show : archiveNdf() — composant Livewire archive et rafraîchit
// ---------------------------------------------------------------------------

it('show: archiveNdf() archive la NDF et met à jour le composant', function () {
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Show;
    $component->association = $this->asso;
    $component->noteDeFrais = $ndf;

    $component->archiveNdf();

    expect($ndf->fresh()->archived_at)->not->toBeNull()
        ->and($component->noteDeFrais->isArchived())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 25. Show : archiveNdf() sur NDF d'un autre tiers → AuthorizationException
// ---------------------------------------------------------------------------

it('show: archiveNdf() sur NDF d\'un autre tiers → AuthorizationException', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    $ndf = NoteDeFrais::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
    ]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Show;
    $component->association = $this->asso;
    // On bypass le mount() (qui ferait 403 sur view) en settant directement
    // en contournant — on teste le archiveNdf isolément via Gate forUser
    // (la policy authorize() dans archiveNdf lèvera l'exception)
    $component->noteDeFrais = $ndf;

    expect(fn () => $component->archiveNdf())
        ->toThrow(AuthorizationException::class);
});
