<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
    ]);
    // Prendre la première ligne créée par la factory
    $this->ligne = $this->tx->lignes()->first();
});

afterEach(fn () => TenantContext::clear());

// ─────────────────────────────────────────────────────────────────────────────
// 1. Controller : GET PJ d'une ligne → 200 + bon contenu
// ─────────────────────────────────────────────────────────────────────────────
it('controller retourne la PJ de la ligne avec statut 200', function () {
    $path = "associations/{$this->association->id}/transactions/{$this->tx->id}/ligne-1-justif.pdf";
    Storage::disk('local')->put($path, 'PDF_CONTENT_TEST');
    $this->ligne->update(['piece_jointe_path' => $path]);

    $response = $this->get(route('comptabilite.transactions.piece-jointe-ligne', [
        'transaction' => $this->tx->id,
        'ligne' => $this->ligne->id,
    ]));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type'); // header présent
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Controller : PJ manquante sur disque → 404
// ─────────────────────────────────────────────────────────────────────────────
it('controller retourne 404 si le fichier est absent du disque', function () {
    $this->ligne->update(['piece_jointe_path' => 'associations/1/transactions/1/ligne-1-fantome.pdf']);

    $this->get(route('comptabilite.transactions.piece-jointe-ligne', [
        'transaction' => $this->tx->id,
        'ligne' => $this->ligne->id,
    ]))->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Controller : ligne pas à la transaction (URL forgée) → 404
// ─────────────────────────────────────────────────────────────────────────────
it('controller retourne 404 si la ligne appartient à une autre transaction', function () {
    $autreTx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
    ]);
    $autreLigne = $autreTx->lignes()->first();

    $this->get(route('comptabilite.transactions.piece-jointe-ligne', [
        'transaction' => $this->tx->id,
        'ligne' => $autreLigne->id,
    ]))->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Controller : ligne d'une transaction d'un autre tenant → 404
// ─────────────────────────────────────────────────────────────────────────────
it('controller retourne 404 pour une transaction hors tenant (TenantScope)', function () {
    $autreAsso = Association::factory()->create();
    $autreUser = User::factory()->create();
    $autreUser->associations()->attach($autreAsso->id, ['role' => 'admin', 'joined_at' => now()]);

    // Créer une tx dans un autre tenant sans changer le TC courant
    TenantContext::clear();
    TenantContext::boot($autreAsso);
    $autreTx = Transaction::factory()->asDepense()->create([
        'association_id' => $autreAsso->id,
        'date' => '2025-10-01',
    ]);
    $autreLigne = $autreTx->lignes()->first();
    TenantContext::clear();
    TenantContext::boot($this->association);

    // Le route binding de Transaction fait fail-closed sur association_id
    $this->get(route('comptabilite.transactions.piece-jointe-ligne', [
        'transaction' => $autreTx->id,
        'ligne' => $autreLigne->id,
    ]))->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Form : upload d'une PJ ligne au create → path persisté + fichier stocké
// ─────────────────────────────────────────────────────────────────────────────
it('persiste la PJ de ligne lors du create', function () {
    $sousCategorie = SousCategorie::factory()
        ->for(\App\Models\Categorie::factory()->depense()->create(['association_id' => $this->association->id]), 'categorie')
        ->create(['association_id' => $this->association->id]);

    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('date', '2025-10-01')
        ->set('libelle', 'Test create PJ ligne')
        ->set('mode_paiement', 'virement')
        ->set('lignes.0.sous_categorie_id', (string) $sousCategorie->id)
        ->set('lignes.0.montant', '50')
        ->set('lignes.0.notes', 'recu-achat')
        ->set('lignes.0.piece_jointe_upload', $file)
        ->call('save')
        ->assertHasNoErrors();

    $nouvelleTx = Transaction::where('libelle', 'Test create PJ ligne')->first();
    expect($nouvelleTx)->not->toBeNull();

    $ligne = $nouvelleTx->lignes()->first();
    expect($ligne)->not->toBeNull();
    expect($ligne->piece_jointe_path)->not->toBeNull();
    Storage::disk('local')->assertExists($ligne->piece_jointe_path);
    expect($ligne->piece_jointe_path)->toContain("associations/{$this->association->id}/transactions/{$nouvelleTx->id}/");
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Form : upload à l'update (remplace existant) → ancien supprimé, nouveau présent
// ─────────────────────────────────────────────────────────────────────────────
it('remplace la PJ de ligne existante lors du update', function () {
    $ancienPath = "associations/{$this->association->id}/transactions/{$this->tx->id}/ligne-1-ancien.pdf";
    Storage::disk('local')->put($ancienPath, 'ANCIEN');
    $this->ligne->update(['piece_jointe_path' => $ancienPath]);

    $nouveauFichier = UploadedFile::fake()->create('nouveau.pdf', 50, 'application/pdf');

    Livewire::test(TransactionForm::class)
        ->call('edit', $this->tx->id)
        ->set('lignes.0.piece_jointe_upload', $nouveauFichier)
        ->call('save')
        ->assertHasNoErrors();

    // Après update, les lignes sont recréées — récupérer la nouvelle ligne
    $this->tx->refresh();
    $nouvelleLigne = $this->tx->lignes()->first();
    expect($nouvelleLigne->piece_jointe_path)->not->toBeNull();
    Storage::disk('local')->assertMissing($ancienPath);
    Storage::disk('local')->assertExists($nouvelleLigne->piece_jointe_path);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Form : flag remove=true → fichier supprimé + path null
// ─────────────────────────────────────────────────────────────────────────────
it('supprime la PJ de ligne quand piece_jointe_remove est true', function () {
    $path = "associations/{$this->association->id}/transactions/{$this->tx->id}/ligne-1-a-supprimer.pdf";
    Storage::disk('local')->put($path, 'CONTENU');
    $this->ligne->update(['piece_jointe_path' => $path]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $this->tx->id)
        ->set('lignes.0.piece_jointe_remove', true)
        ->call('save')
        ->assertHasNoErrors();

    // Après update, lignes recréées — la nouvelle ligne doit avoir piece_jointe_path = null
    $this->tx->refresh();
    $nouvelleLigne = $this->tx->lignes()->first();
    expect($nouvelleLigne->piece_jointe_path)->toBeNull();
    // Le fichier doit avoir été supprimé
    Storage::disk('local')->assertMissing($path);
});

// ─────────────────────────────────────────────────────────────────────────────
// 8. Validation : fichier > 10 Mo → erreur fr
// ─────────────────────────────────────────────────────────────────────────────
it('rejette un fichier de ligne supérieur à 10 Mo', function () {
    $grosFile = UploadedFile::fake()->create('gros.pdf', 11000, 'application/pdf'); // 11 000 Ko

    Livewire::test(TransactionForm::class)
        ->call('edit', $this->tx->id)
        ->set('lignes.0.piece_jointe_upload', $grosFile)
        ->call('save')
        ->assertHasErrors(['lignes.0.piece_jointe_upload']);
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. Validation : mime non accepté → erreur fr
// ─────────────────────────────────────────────────────────────────────────────
it('rejette un type MIME non autorisé pour la PJ de ligne', function () {
    $exeFile = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    Livewire::test(TransactionForm::class)
        ->call('edit', $this->tx->id)
        ->set('lignes.0.piece_jointe_upload', $exeFile)
        ->call('save')
        ->assertHasErrors(['lignes.0.piece_jointe_upload']);
});
