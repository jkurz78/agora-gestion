<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Http\Resources\Portail\TransactionDepensePubliqueResource;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

it('toArray retourne exactement les 5 clés attendues — pas plus', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'numero_piece' => 'FAC-2026-001',
        'piece_jointe_path' => null,
        'notes' => 'note interne',
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect(array_keys($result))->toBe(['date_piece', 'notre_ref', 'ref', 'montant_ttc', 'statut_reglement', 'pdf_url']);
});

it('notre_ref utilise numero_piece quand renseigné', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'numero_piece' => 'FAC-2026-042',
        'libelle' => 'Achat matériel',
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['notre_ref'])->toBe('FAC-2026-042');
});

it('notre_ref utilise libelle quand numero_piece est null', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'numero_piece' => null,
        'libelle' => 'Facture eau',
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['notre_ref'])->toBe('Facture eau');
});

it('ref expose la référence du tiers (Transaction.reference)', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'reference' => 'TIERS-INV-2026-007',
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['ref'])->toBe('TIERS-INV-2026-007');
});

it('ref est null si Transaction.reference est null', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'reference' => null,
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['ref'])->toBeNull();
});

it('date_piece est au format Y-m-d', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'date' => '2026-03-15',
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['date_piece'])->toBe('2026-03-15');
});

it('statut_reglement vaut "En attente" pour EnAttente', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'statut_reglement' => StatutReglement::EnAttente,
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['statut_reglement'])->toBe('En attente');
});

it('statut_reglement vaut "Réglée" pour Recu', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'statut_reglement' => StatutReglement::Recu,
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['statut_reglement'])->toBe('Réglée');
});

it('statut_reglement vaut "Réglée" pour Pointe', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'statut_reglement' => StatutReglement::Pointe,
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['statut_reglement'])->toBe('Réglée');
});

it('pdf_url est null quand aucune pièce jointe', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['pdf_url'])->toBeNull();
});

it('pdf_url est non-null et signée quand pièce jointe présente', function () {
    // Register mock route so URL::signedRoute can resolve it.
    // refreshNameLookups() is required because ->name() is called after add(), so the
    // nameList is not populated until an explicit refresh.
    Route::get('/portail/{association}/historique/{transaction}/pdf', fn () => 'fake')
        ->name('portail.historique.pdf');
    app('router')->getRoutes()->refreshNameLookups();

    $transaction = Transaction::factory()->asDepense()->create([
        'piece_jointe_path' => 'associations/1/transactions/42/facture.pdf',
        'piece_jointe_nom' => 'facture.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['pdf_url'])->not->toBeNull();
    // Une URL signée contient le paramètre de signature
    expect($result['pdf_url'])->toContain('signature=');
});

it('toArray ne fait pas fuiter de champs internes (notes, lignes, analytique…)', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'notes' => 'confidentiel interne',
        'piece_jointe_path' => null,
    ]);
    // Charger les lignes pour s'assurer qu'elles sont disponibles sur le modèle
    $transaction->load('lignes');

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect(array_keys($result))->toBe(['date_piece', 'notre_ref', 'ref', 'montant_ttc', 'statut_reglement', 'pdf_url']);
});

it('montant_ttc est un float', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'montant_total' => '1234.56',
        'piece_jointe_path' => null,
    ]);

    $resource = new TransactionDepensePubliqueResource($transaction);
    $resource->withAssociationSlug('mon-asso');

    $result = $resource->toArray(Request::create('/'));

    expect($result['montant_ttc'])->toBeFloat();
    expect($result['montant_ttc'])->toBe(1234.56);
});
