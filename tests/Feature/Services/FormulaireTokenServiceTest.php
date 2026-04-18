<?php

declare(strict_types=1);

use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use App\Models\Association;
use App\Tenant\TenantContext;
use App\Services\FormulaireTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(FormulaireTokenService::class);
});

afterEach(function () {
    TenantContext::clear();
});

describe('generate()', function () {
    it('creates a token with correct format XXXX-XXXX', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token = $this->service->generate($participant);

        expect($token->token)->toMatch('/^[3456789ABCDEFGHJKMNPQRSTVWXY]{4}-[3456789ABCDEFGHJKMNPQRSTVWXY]{4}$/');
    });

    it('uses operation date_debut - 1 day as default expiration', function () {
        $dateDebut = now()->addMonths(2)->format('Y-m-d');
        $operation = Operation::factory()->create(['date_debut' => $dateDebut]);
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token = $this->service->generate($participant);

        $expected = now()->addMonths(2)->subDay()->format('Y-m-d');
        expect($token->expire_at->format('Y-m-d'))->toBe($expected);
    });

    it('falls back to 30 days when date_debut is in the past', function () {
        $operation = Operation::factory()->create(['date_debut' => '2025-01-01']);
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token = $this->service->generate($participant);

        expect($token->expire_at->format('Y-m-d'))->toBe(now()->addDays(30)->format('Y-m-d'));
    });

    it('replaces existing token for same participant', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token1 = $this->service->generate($participant);
        $token2 = $this->service->generate($participant);

        expect(FormulaireToken::count())->toBe(1)
            ->and($token2->token)->not->toBe($token1->token);
    });

    it('accepts custom expiration date', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token = $this->service->generate($participant, '2026-06-15');

        expect($token->expire_at->format('Y-m-d'))->toBe('2026-06-15');
    });
});

describe('validate()', function () {
    it('returns valid status with participant for valid token', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);
        $token = $this->service->generate($participant);

        $result = $this->service->validate($token->token);

        expect($result['status'])->toBe('valid')
            ->and($result['participant']->id)->toBe($participant->id);
    });

    it('returns invalid status for unknown token', function () {
        $result = $this->service->validate('ZZZZ-ZZZZ');
        expect($result['status'])->toBe('invalid')
            ->and($result['participant'])->toBeNull();
    });

    it('returns expired status for expired token', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);
        FormulaireToken::create([
            'participant_id' => $participant->id,
            'token' => 'ABCD-EFGH',
            'expire_at' => now()->subDay()->toDateString(),
        ]);

        $result = $this->service->validate('ABCD-EFGH');
        expect($result['status'])->toBe('expired');
    });

    it('returns used status for already-used token', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);
        FormulaireToken::create([
            'participant_id' => $participant->id,
            'token' => 'ABCD-EFGH',
            'expire_at' => now()->addDays(7)->toDateString(),
            'rempli_at' => now(),
        ]);

        $result = $this->service->validate('ABCD-EFGH');
        expect($result['status'])->toBe('used');
    });

    it('normalizes token input (lowercase, no tiret, spaces)', function () {
        $operation = Operation::factory()->create();
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);
        $token = $this->service->generate($participant);
        $rawCode = str_replace('-', '', strtolower($token->token));

        $result = $this->service->validate("  {$rawCode}  ");

        expect($result['status'])->toBe('valid');
    });
});
