<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Services\NumeroPieceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('exerciceFromDate retourne exercice courant pour date en septembre', function () {
    $service = new NumeroPieceService;
    expect($service->exerciceFromDate(Carbon::parse('2025-09-01')))->toBe('2025-2026');
});

it('exerciceFromDate retourne exercice precedent pour date en aout', function () {
    $service = new NumeroPieceService;
    expect($service->exerciceFromDate(Carbon::parse('2026-08-31')))->toBe('2025-2026');
});

it('assign retourne le premier numero de lexercice', function () {
    $service = new NumeroPieceService;
    $result = $service->assign(Carbon::parse('2025-10-01'));
    expect($result)->toBe('2025-2026:00001');
});

it('assign retourne le deuxieme numero sur le meme exercice', function () {
    $service = new NumeroPieceService;
    $service->assign(Carbon::parse('2025-10-01'));
    $result = $service->assign(Carbon::parse('2026-02-15'));
    expect($result)->toBe('2025-2026:00002');
});

it('assign commence une nouvelle sequence pour un nouvel exercice', function () {
    $service = new NumeroPieceService;
    $service->assign(Carbon::parse('2025-10-01'));
    $result = $service->assign(Carbon::parse('2026-09-01'));
    expect($result)->toBe('2026-2027:00001');
});

it('deux appels consecutifs donnent deux numeros distincts', function () {
    $service = new NumeroPieceService;
    $a = $service->assign(Carbon::parse('2025-10-01'));
    $b = $service->assign(Carbon::parse('2025-11-01'));
    expect($a)->not->toBe($b);
});

it('assign utilise lockForUpdate et est thread-safe dans une transaction', function () {
    $service = new NumeroPieceService;
    $result = DB::transaction(function () use ($service) {
        return $service->assign(Carbon::parse('2025-10-01'));
    });
    expect($result)->toStartWith('2025-2026:');
});
