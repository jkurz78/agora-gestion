<?php

declare(strict_types=1);

use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('campagnes_email table exists', function () {
    expect(Schema::hasTable('campagnes_email'))->toBeTrue();

    foreach (['id', 'operation_id', 'objet', 'corps', 'nb_destinataires', 'nb_erreurs', 'envoye_par', 'created_at', 'updated_at'] as $column) {
        expect(Schema::hasColumn('campagnes_email', $column))->toBeTrue("Column {$column} missing");
    }
});

it('can create a CampagneEmail and relates to Operation', function () {
    $user = User::factory()->create();
    $operation = Operation::factory()->create();

    $campagne = CampagneEmail::create([
        'operation_id' => $operation->id,
        'objet' => 'Rappel séance du mois',
        'corps' => 'Bonjour, votre prochaine séance est prévue le {date_prochaine_seance}.',
        'nb_destinataires' => 25,
        'nb_erreurs' => 1,
        'envoye_par' => $user->id,
    ]);

    expect($campagne->id)->toBeInt()
        ->and($campagne->operation_id)->toBe($operation->id)
        ->and($campagne->nb_destinataires)->toBe(25)
        ->and($campagne->nb_erreurs)->toBe(1)
        ->and($campagne->operation)->toBeInstanceOf(Operation::class)
        ->and($campagne->operation->id)->toBe($operation->id)
        ->and($campagne->envoyePar)->toBeInstanceOf(User::class)
        ->and($campagne->envoyePar->id)->toBe($user->id);
});

it('CampagneEmail has emailLogs relation', function () {
    $user = User::factory()->create();
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();

    $campagne = CampagneEmail::create([
        'operation_id' => $operation->id,
        'objet' => 'Test campagne',
        'corps' => 'Corps test',
        'nb_destinataires' => 2,
        'nb_erreurs' => 0,
        'envoye_par' => $user->id,
    ]);

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'categorie' => 'message',
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Test User',
        'objet' => 'Sujet test',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'campagne_id' => $campagne->id,
    ]);

    expect($campagne->emailLogs)->toHaveCount(1)
        ->and($campagne->emailLogs->first()->campagne_id)->toBe($campagne->id);
});

it('email_logs.campagne_id exists and is nullable', function () {
    expect(Schema::hasColumn('email_logs', 'campagne_id'))->toBeTrue();

    $user = User::factory()->create();
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();

    $log = EmailLog::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'categorie' => 'message',
        'destinataire_email' => 'orphan@example.com',
        'destinataire_nom' => 'Orphan',
        'objet' => 'Sans campagne',
        'statut' => 'envoye',
        'envoye_par' => $user->id,
        'campagne_id' => null,
    ]);

    expect($log->campagne_id)->toBeNull()
        ->and($log->campagne)->toBeNull();
});
