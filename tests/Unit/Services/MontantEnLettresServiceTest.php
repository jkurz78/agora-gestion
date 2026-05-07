<?php

declare(strict_types=1);

use App\Services\MontantEnLettresService;

it('formate un montant entier sans centimes', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(150))->toBe('cent cinquante euros');
});

it('formate un montant avec centimes', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(1234.56))->toBe('mille deux cent trente-quatre euros et cinquante-six centimes');
});

it('formate quatre-vingts au pluriel', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(80))->toBe('quatre-vingts euros');
});

it('formate cent au pluriel uniquement quand multiple', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(100))->toBe('cent euros');
    expect($service->convertir(200))->toBe('deux cents euros');
});

it('formate un million', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(1_000_000))->toBe("un million d'euros");
});

it('formate un centime seul', function () {
    $service = app(MontantEnLettresService::class);
    expect($service->convertir(0.01))->toBe('zéro euros et un centime');
});
