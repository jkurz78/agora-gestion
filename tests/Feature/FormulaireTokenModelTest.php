<?php

declare(strict_types=1);

use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
    $this->tiers = Tiers::factory()->create();
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2025-10-01',
    ]);
});

it('creates a FormulaireToken with correct casts', function () {
    $token = FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'KM7R-4NPX',
        'expire_at' => '2025-11-01',
    ]);

    expect($token->expire_at->format('Y-m-d'))->toBe('2025-11-01')
        ->and($token->rempli_at)->toBeNull()
        ->and($token->rempli_ip)->toBeNull();
});

it('has participant relation', function () {
    $token = FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'KM7R-4NPX',
        'expire_at' => '2025-11-01',
    ]);

    expect($token->participant->id)->toBe($this->participant->id);
});

it('participant has formulaireToken relation', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'KM7R-4NPX',
        'expire_at' => '2025-11-01',
    ]);

    expect($this->participant->formulaireToken)->not->toBeNull()
        ->and($this->participant->formulaireToken->token)->toBe('KM7R-4NPX');
});

describe('status methods', function () {
    it('isExpire returns true when expired', function () {
        $token = FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'KM7R-4NPX',
            'expire_at' => now()->subDay()->toDateString(),
        ]);
        expect($token->isExpire())->toBeTrue();
    });

    it('isExpire returns false when not expired', function () {
        $token = FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'KM7R-4NPX',
            'expire_at' => now()->addDays(7)->toDateString(),
        ]);
        expect($token->isExpire())->toBeFalse();
    });

    it('isUtilise returns true when rempli_at is set', function () {
        $token = FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'KM7R-4NPX',
            'expire_at' => now()->addDays(7)->toDateString(),
            'rempli_at' => now(),
        ]);
        expect($token->isUtilise())->toBeTrue();
    });

    it('isValide returns true only when not expired and not used', function () {
        $token = FormulaireToken::create([
            'participant_id' => $this->participant->id,
            'token' => 'KM7R-4NPX',
            'expire_at' => now()->addDays(7)->toDateString(),
        ]);
        expect($token->isValide())->toBeTrue();
    });
});

it('cascades on participant delete', function () {
    $token = FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'KM7R-4NPX',
        'expire_at' => '2025-11-01',
    ]);
    $tokenId = $token->id;

    $this->participant->delete();

    expect(FormulaireToken::find($tokenId))->toBeNull();
});
