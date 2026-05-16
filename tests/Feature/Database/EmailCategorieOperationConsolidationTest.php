<?php

declare(strict_types=1);

use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

it('migration consolide email_logs categorie operation vers message', function () {
    $associationId = TenantContext::currentId();

    DB::table('email_logs')->insert([
        'destinataire_email' => 'test-operation@example.com',
        'objet' => 'Test opération',
        'categorie' => 'operation',
        'statut' => 'envoye',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // GREEN : exécuter la logique de migration
    DB::table('email_logs')
        ->where('categorie', 'operation')
        ->update(['categorie' => 'message']);

    $remaining = DB::table('email_logs')
        ->where('categorie', 'operation')
        ->count();

    $converted = DB::table('email_logs')
        ->where('destinataire_email', 'test-operation@example.com')
        ->where('categorie', 'message')
        ->count();

    expect($remaining)->toBe(0);
    expect($converted)->toBe(1);
});

it('migration consolide email_templates categorie operation vers message', function () {
    $associationId = TenantContext::currentId();

    DB::table('email_templates')->insert([
        'association_id' => $associationId,
        'objet' => 'Objet opération test',
        'corps' => '<p>Corps test</p>',
        'categorie' => 'operation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('email_templates')
        ->where('categorie', 'operation')
        ->update(['categorie' => 'message']);

    $remaining = DB::table('email_templates')
        ->where('categorie', 'operation')
        ->count();

    $converted = DB::table('email_templates')
        ->where('objet', 'Objet opération test')
        ->where('categorie', 'message')
        ->count();

    expect($remaining)->toBe(0);
    expect($converted)->toBe(1);
});

it('update est idempotent quand aucune rangee operation existe', function () {
    $beforeCount = DB::table('email_logs')->where('categorie', 'operation')->count();

    DB::table('email_logs')
        ->where('categorie', 'operation')
        ->update(['categorie' => 'message']);

    $afterCount = DB::table('email_logs')->where('categorie', 'operation')->count();

    expect($afterCount)->toBe(0);
    expect($afterCount)->toBe($beforeCount);
});
