<?php

declare(strict_types=1);

use App\Support\Demo\EncryptedColumnsRegistry;

beforeEach(function (): void {
    EncryptedColumnsRegistry::clearCache();
});

afterEach(function (): void {
    EncryptedColumnsRegistry::clearCache();
});

// T1 : all() retourne au moins les 7 tables connues du projet
test('all() returns at least the known tables with encrypted columns', function (): void {
    $map = EncryptedColumnsRegistry::all();

    expect($map)->toHaveKey('association');
    expect($map)->toHaveKey('helloasso_parametres');
    expect($map)->toHaveKey('incoming_mail_parametres');
    expect($map)->toHaveKey('smtp_parametres');
    expect($map)->toHaveKey('users');
    expect($map)->toHaveKey('presences');
    expect($map)->toHaveKey('participant_donnees_medicales');
});

// T2 : forTable('presences') retourne les 3 colonnes attendues
test('forTable(presences) returns the 3 expected encrypted columns', function (): void {
    $cols = EncryptedColumnsRegistry::forTable('presences');

    expect($cols)->toContain('statut');
    expect($cols)->toContain('kine');
    expect($cols)->toContain('commentaire');
    expect(count($cols))->toBe(3);
});

// T2b : forTable('users') inclut two_factor_secret ET two_factor_recovery_codes
test('forTable(users) includes two_factor_secret and two_factor_recovery_codes', function (): void {
    $cols = EncryptedColumnsRegistry::forTable('users');

    expect($cols)->toContain('two_factor_secret');
    expect($cols)->toContain('two_factor_recovery_codes');
});

// T2c : forTable('association') inclut anthropic_api_key
test('forTable(association) includes anthropic_api_key', function (): void {
    $cols = EncryptedColumnsRegistry::forTable('association');

    expect($cols)->toContain('anthropic_api_key');
});

// T2d : forTable('participant_donnees_medicales') inclut les colonnes médicales
test('forTable(participant_donnees_medicales) includes medical columns', function (): void {
    $cols = EncryptedColumnsRegistry::forTable('participant_donnees_medicales');

    expect($cols)->toContain('date_naissance');
    expect($cols)->toContain('sexe');
    expect($cols)->toContain('poids');
    expect($cols)->toContain('taille');
    expect($cols)->toContain('notes');
    expect($cols)->toContain('medecin_nom');
    expect($cols)->toContain('therapeute_nom');
});

// T3 : forTable('table_inexistante') retourne []
test('forTable with unknown table returns empty array', function (): void {
    $cols = EncryptedColumnsRegistry::forTable('table_inexistante');

    expect($cols)->toBe([]);
});

// T4 : all() est mis en cache (appel répété retourne la même référence)
test('all() caches its result across multiple calls', function (): void {
    $first = EncryptedColumnsRegistry::all();
    $second = EncryptedColumnsRegistry::all();

    expect($second)->toBe($first);
});
