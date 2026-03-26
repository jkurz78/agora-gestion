# Formulaire auto-déclaratif participants — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Public self-service form where participants enter their personal and medical data via a unique token link, without authentication.

**Architecture:** A `formulaire_tokens` table links short readable tokens (8 chars, `XXXX-XXXX` format) to participants. A public controller validates tokens, displays a pre-filled form (coordinates from Tiers + empty medical fields + file upload), and writes data with a merge-intelligent strategy. Token generation and tracking are integrated into the existing ParticipantTable Livewire component.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Alpine.js, Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-26-formulaire-participant-design.md`

---

### Task 1: Migration + FormulaireToken model

**Files:**
- Create: `database/migrations/2026_03_26_200001_create_formulaire_tokens_table.php`
- Create: `app/Models/FormulaireToken.php`
- Modify: `app/Models/Participant.php` — add `formulaireToken()` HasOne relation
- Test: `tests/Feature/FormulaireTokenModelTest.php`

- [ ] **Step 1: Write test**

```php
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
```

- [ ] **Step 2: Run test — verify it fails**

Run: `./vendor/bin/sail test tests/Feature/FormulaireTokenModelTest.php`

- [ ] **Step 3: Write migration**

File: `database/migrations/2026_03_26_200001_create_formulaire_tokens_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulaire_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->unique()->constrained('participants')->cascadeOnDelete();
            $table->string('token', 9)->unique();
            $table->date('expire_at');
            $table->dateTime('rempli_at')->nullable();
            $table->string('rempli_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulaire_tokens');
    }
};
```

- [ ] **Step 4: Create FormulaireToken model**

File: `app/Models/FormulaireToken.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FormulaireToken extends Model
{
    protected $table = 'formulaire_tokens';

    protected $fillable = [
        'participant_id',
        'token',
        'expire_at',
        'rempli_at',
        'rempli_ip',
    ];

    protected function casts(): array
    {
        return [
            'participant_id' => 'integer',
            'expire_at' => 'date',
            'rempli_at' => 'datetime',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function isExpire(): bool
    {
        return $this->expire_at->lt(today());
    }

    public function isUtilise(): bool
    {
        return $this->rempli_at !== null;
    }

    public function isValide(): bool
    {
        return ! $this->isExpire() && ! $this->isUtilise();
    }
}
```

- [ ] **Step 5: Add relation to Participant model**

In `app/Models/Participant.php`, add the relation and a `booted` event to clean up files on delete:

```php
public function formulaireToken(): HasOne
{
    return $this->hasOne(FormulaireToken::class);
}
```

In the `booted()` static method (create if it doesn't exist):

```php
protected static function booted(): void
{
    static::deleting(function (Participant $participant) {
        $dir = "participants/{$participant->id}";
        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($dir)) {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($dir);
        }
    });
}
```

- [ ] **Step 6: Run test — verify it passes**

Run: `./vendor/bin/sail test tests/Feature/FormulaireTokenModelTest.php`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_03_26_200001* app/Models/FormulaireToken.php app/Models/Participant.php tests/Feature/FormulaireTokenModelTest.php
git commit -m "feat(formulaire): add FormulaireToken model and migration"
```

---

### Task 2: FormulaireTokenService — token generation and validation

**Files:**
- Create: `app/Services/FormulaireTokenService.php`
- Test: `tests/Feature/Services/FormulaireTokenServiceTest.php`

- [ ] **Step 1: Write test**

```php
<?php

declare(strict_types=1);

use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FormulaireTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(FormulaireTokenService::class);
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
        $operation = Operation::factory()->create(['date_debut' => '2025-12-01']);
        $tiers = Tiers::factory()->create();
        $participant = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => '2025-10-01',
        ]);

        $token = $this->service->generate($participant);

        expect($token->expire_at->format('Y-m-d'))->toBe('2025-11-30');
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
```

- [ ] **Step 2: Run test — verify it fails**

- [ ] **Step 3: Write FormulaireTokenService**

File: `app/Services/FormulaireTokenService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FormulaireToken;
use App\Models\Participant;

final class FormulaireTokenService
{
    private const ALPHABET = '3456789ABCDEFGHJKMNPQRSTVWXY';

    public function generate(Participant $participant, ?string $expireAt = null): FormulaireToken
    {
        // Delete existing token for this participant
        FormulaireToken::where('participant_id', $participant->id)->delete();

        $token = $this->generateUniqueToken();

        if ($expireAt === null) {
            $operation = $participant->operation;
            if ($operation->date_debut !== null && $operation->date_debut->gt(today())) {
                $expireAt = $operation->date_debut->subDay()->format('Y-m-d');
            } else {
                $expireAt = now()->addDays(30)->format('Y-m-d');
            }
        }

        return FormulaireToken::create([
            'participant_id' => $participant->id,
            'token' => $token,
            'expire_at' => $expireAt,
        ]);
    }

    /**
     * @return array{status: 'valid'|'invalid'|'expired'|'used', participant: ?Participant}
     */
    public function validate(string $input): array
    {
        $normalized = $this->normalizeToken($input);

        $formulaireToken = FormulaireToken::where('token', $normalized)->first();

        if ($formulaireToken === null) {
            return ['status' => 'invalid', 'participant' => null];
        }

        if ($formulaireToken->isUtilise()) {
            return ['status' => 'used', 'participant' => null];
        }

        if ($formulaireToken->isExpire()) {
            return ['status' => 'expired', 'participant' => null];
        }

        return ['status' => 'valid', 'participant' => $formulaireToken->participant];
    }

    private function generateUniqueToken(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $token = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        } while (FormulaireToken::where('token', $token)->exists());

        return $token;
    }

    private function normalizeToken(string $input): string
    {
        $clean = strtoupper(trim(str_replace(' ', '', $input)));
        // Remove tiret for uniform handling, then re-add
        $clean = str_replace('-', '', $clean);
        if (strlen($clean) === 8) {
            return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
        }

        return $clean;
    }
}
```

- [ ] **Step 4: Run test — verify it passes**

- [ ] **Step 5: Commit**

```bash
git add app/Services/FormulaireTokenService.php tests/Feature/Services/FormulaireTokenServiceTest.php
git commit -m "feat(formulaire): add FormulaireTokenService with generate() and validate()"
```

---

### Task 3: FormulaireController — public routes + form display + submission

**Files:**
- Create: `app/Http/Controllers/FormulaireController.php`
- Create: `resources/views/formulaire/layout.blade.php`
- Create: `resources/views/formulaire/index.blade.php`
- Create: `resources/views/formulaire/remplir.blade.php`
- Modify: `routes/web.php` — add public routes
- Test: `tests/Feature/FormulaireControllerTest.php`

- [ ] **Step 1: Write test**

Tests covering: index page renders, redirect with token param, show form with valid token, reject invalid/expired/used token, successful form submission (merge coordinates + write medical data + store files + mark token used), rate limiting.

- [ ] **Step 2: Run test — verify it fails**

- [ ] **Step 3: Add routes to web.php**

Before the auth routes at the bottom, add:

```php
// Public formulaire (no auth required)
Route::prefix('formulaire')->middleware('throttle:10,1')->group(function (): void {
    Route::get('/', [\App\Http\Controllers\FormulaireController::class, 'index'])->name('formulaire.index');
    Route::get('/remplir', [\App\Http\Controllers\FormulaireController::class, 'show'])->name('formulaire.show');
    Route::post('/remplir', [\App\Http\Controllers\FormulaireController::class, 'store'])->name('formulaire.store');
});
```

- [ ] **Step 4: Create public layout**

File: `resources/views/formulaire/layout.blade.php`

Minimal layout (NOT `<x-app-layout>`) with:
- Bootstrap 5 CDN (same as app)
- Association logo (centered) loaded via `Association::find(1)` pattern from `guest.blade.php`
- Association name
- `@yield('content')` slot
- Discreet footer

- [ ] **Step 5: Create index page**

File: `resources/views/formulaire/index.blade.php`

- Extends `formulaire.layout`
- Title: "Formulaire participant"
- Explanation text
- Token input field (text, autocapitalize, placeholder `XXXX-XXXX`)
- Submit button → GET `/formulaire/remplir?token=...`
- Flash success message (for post-submission "merci")
- Error messages

- [ ] **Step 6: Create form page**

File: `resources/views/formulaire/remplir.blade.php`

- Extends `formulaire.layout`
- Logo + greeting: "Bonjour {prénom} {nom}"
- Operation context: "Votre inscription à {opération}, du {date_debut} au {date_fin}, {nombre_seances} séances."
- Section Coordonnées: telephone, email, adresse_ligne1, code_postal, ville (pre-filled from Tiers)
- Section Données de santé: bandeau confidentialité, date_naissance, sexe (select), taille, poids, notes (textarea)
- Section Documents: invite text, 3 file inputs (accept PDF/JPG/PNG)
- Hidden field: token
- "Envoyer" button → opens Bootstrap confirmation modal (JS builds recap from form fields)
- Modal: recap + "Confirmer" (submits POST) + "Modifier" (closes modal)

- [ ] **Step 7: Create FormulaireController**

File: `app/Http/Controllers/FormulaireController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\ParticipantDonneesMedicales;
use App\Services\FormulaireTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class FormulaireController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('token')) {
            return redirect()->route('formulaire.show', ['token' => $request->input('token')]);
        }

        return view('formulaire.index');
    }

    public function show(Request $request)
    {
        $service = app(FormulaireTokenService::class);
        $result = $service->validate($request->input('token', ''));

        if ($result['status'] === 'used') {
            return redirect()->route('formulaire.index')
                ->with('info', 'Ce formulaire a déjà été rempli. Merci.');
        }

        if ($result['status'] !== 'valid') {
            return redirect()->route('formulaire.index')
                ->withErrors(['token' => 'Code invalide ou expiré.']);
        }

        $participant = $result['participant'];
        $participant->load(['tiers', 'operation']);

        return view('formulaire.remplir', [
            'participant' => $participant,
            'tiers' => $participant->tiers,
            'operation' => $participant->operation,
            'token' => $request->input('token'),
        ]);
    }

    public function store(Request $request)
    {
        $service = app(FormulaireTokenService::class);
        $result = $service->validate($request->input('token', ''));

        if ($result['status'] !== 'valid') {
            return redirect()->route('formulaire.index')
                ->withErrors(['token' => 'Code invalide ou expiré.']);
        }

        $participant = $result['participant'];

        $request->validate([
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'adresse_ligne1' => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'sexe' => ['nullable', 'in:M,F'],
            'taille' => ['nullable', 'numeric', 'between:50,250'],
            'poids' => ['nullable', 'numeric', 'between:20,300'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'documents.*' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents' => ['nullable', 'array', 'max:3'],
        ]);

        $tiers = $participant->tiers;

        // Merge intelligent: only update non-empty changed values
        $coordFields = ['telephone', 'email', 'adresse_ligne1', 'code_postal', 'ville'];
        foreach ($coordFields as $field) {
            $newValue = $request->input($field, '');
            if ($newValue !== '' && $newValue !== ($tiers->{$field} ?? '')) {
                $tiers->{$field} = $newValue;
            }
        }
        $tiers->save();

        // Write medical data
        ParticipantDonneesMedicales::updateOrCreate(
            ['participant_id' => $participant->id],
            [
                'date_naissance' => $request->input('date_naissance') ?: null,
                'sexe' => $request->input('sexe') ?: null,
                'taille' => $request->input('taille') ?: null,
                'poids' => $request->input('poids') ?: null,
                'notes' => $request->input('notes') ?: null,
            ]
        );

        // Store documents
        if ($request->hasFile('documents')) {
            $dir = "participants/{$participant->id}";
            foreach ($request->file('documents') as $file) {
                if ($file->isValid()) {
                    $file->store($dir, 'local');
                }
            }
        }

        // Mark token as used
        $formulaireToken = $participant->formulaireToken;
        $formulaireToken->update([
            'rempli_at' => now(),
            'rempli_ip' => $request->ip(),
        ]);

        return redirect()->route('formulaire.index')
            ->with('success', 'Merci ! Vos informations ont bien été enregistrées. Vous pouvez fermer cette page.');
    }
}
```

- [ ] **Step 8: Run tests — verify they pass**

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/FormulaireController.php resources/views/formulaire/ routes/web.php tests/Feature/FormulaireControllerTest.php
git commit -m "feat(formulaire): add public form controller, views, and routes"
```

---

### Task 4: ParticipantTable integration — token generation + status badges

**Files:**
- Modify: `app/Livewire/ParticipantTable.php` — add `genererToken()`, `showTokenModal`, properties
- Modify: `resources/views/livewire/participant-table.blade.php` — add badge column, token modal, generate button
- Test: `tests/Feature/Livewire/FormulaireTokenIntegrationTest.php`

- [ ] **Step 1: Write test**

Tests covering: genererToken creates a token and shows modal, badge displays correctly for each state (no token, pending, expired, filled), token modal shows link and code.

- [ ] **Step 2: Run test — verify it fails**

- [ ] **Step 3: Add properties and methods to ParticipantTable**

Add to `app/Livewire/ParticipantTable.php`:

Properties:
```php
public bool $showTokenModal = false;
public ?string $tokenCode = null;
public ?string $tokenUrl = null;
public ?string $tokenExpireAt = null;
public ?int $tokenParticipantId = null;
```

Methods:
```php
public function genererToken(int $participantId): void
{
    $participant = Participant::where('operation_id', $this->operation->id)
        ->findOrFail($participantId);

    $token = app(FormulaireTokenService::class)->generate($participant, $this->tokenExpireAt);

    $this->tokenCode = $token->token;
    $this->tokenUrl = route('formulaire.index', ['token' => $token->token]);
    $this->tokenExpireAt = $token->expire_at->format('Y-m-d');
    $this->tokenParticipantId = $participantId;
    $this->showTokenModal = true;
}

public function genererTokenAvecDate(): void
{
    if ($this->tokenParticipantId === null) {
        return;
    }
    $this->genererToken($this->tokenParticipantId);
}

public function ouvrirToken(int $participantId): void
{
    $participant = Participant::where('operation_id', $this->operation->id)
        ->with('formulaireToken')
        ->findOrFail($participantId);

    $token = $participant->formulaireToken;
    if ($token === null) {
        return;
    }

    $this->tokenCode = $token->token;
    $this->tokenUrl = route('formulaire.index', ['token' => $token->token]);
    $this->tokenExpireAt = $token->expire_at->format('Y-m-d');
    $this->tokenParticipantId = $participantId;
    $this->showTokenModal = true;
}
```

In `render()`, eager-load `formulaireToken` on the participants query.

- [ ] **Step 4: Update Blade view — add badge column**

In the table header, add a "Formulaire" column. In the row loop, add the badge logic:

```blade
<td class="text-center small">
    @if ($p->formulaireToken === null)
        <button wire:click="genererToken({{ $p->id }})" class="btn btn-sm btn-outline-secondary" title="Générer un lien">
            <i class="bi bi-link-45deg"></i>
        </button>
    @elseif ($p->formulaireToken->isUtilise())
        <span class="badge bg-success" title="Rempli le {{ $p->formulaireToken->rempli_at->format('d/m/Y') }}">
            <i class="bi bi-check-circle"></i> Rempli
        </span>
    @elseif ($p->formulaireToken->isExpire())
        <span class="badge bg-secondary" role="button" wire:click="genererToken({{ $p->id }})" title="Expiré — cliquer pour regénérer">
            <i class="bi bi-clock-history"></i> Expiré
        </span>
    @else
        <span class="badge bg-warning text-dark" role="button" wire:click="ouvrirToken({{ $p->id }})" title="En attente — cliquer pour voir le code">
            <i class="bi bi-hourglass"></i> En attente
        </span>
    @endif
</td>
```

Add a token modal at the bottom of the view (same modal pattern as edit modal):
- Display: token code (large, monospace), full URL, expiration date (editable input)
- Buttons: "Copier le lien" (Alpine.js clipboard), "Regénérer" (wire:click), "Fermer"

- [ ] **Step 5: Run tests — verify they pass**

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/ParticipantTable.php resources/views/livewire/participant-table.blade.php tests/Feature/Livewire/FormulaireTokenIntegrationTest.php
git commit -m "feat(formulaire): integrate token generation and status badges in ParticipantTable"
```

---

### Task 5: Document download route (authenticated)

**Files:**
- Create: `app/Http/Controllers/ParticipantDocumentController.php`
- Modify: `routes/web.php` — add download route in Gestion group
- Test: `tests/Feature/ParticipantDocumentTest.php`

- [ ] **Step 1: Write test**

Tests covering: download works with permission, refuses without permission, returns 404 for missing file.

- [ ] **Step 2: Run test — verify it fails**

- [ ] **Step 3: Create controller**

File: `app/Http/Controllers/ParticipantDocumentController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class ParticipantDocumentController extends Controller
{
    public function __invoke(Request $request, Participant $participant, string $filename)
    {
        if (! $request->user()->peut_voir_donnees_sensibles) {
            abort(403);
        }

        $path = "participants/{$participant->id}/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->download($path, $filename);
    }
}
```

- [ ] **Step 4: Add route in Gestion group**

In `routes/web.php`, inside the Gestion group:

```php
Route::get('/participants/{participant}/documents/{filename}', \App\Http\Controllers\ParticipantDocumentController::class)
    ->name('participants.documents.download');
```

- [ ] **Step 5: Run tests — verify they pass**

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ParticipantDocumentController.php routes/web.php tests/Feature/ParticipantDocumentTest.php
git commit -m "feat(formulaire): add authenticated document download route"
```

---

### Task 6: Display uploaded documents in ParticipantTable

**Files:**
- Modify: `app/Livewire/ParticipantTable.php` — add method to list documents
- Modify: `resources/views/livewire/participant-table.blade.php` — add documents section in edit modal

- [ ] **Step 1: Add helper method to ParticipantTable**

```php
private function getParticipantDocuments(int $participantId): array
{
    $dir = "participants/{$participantId}";
    if (! Storage::disk('local')->exists($dir)) {
        return [];
    }

    return collect(Storage::disk('local')->files($dir))
        ->map(fn (string $path) => [
            'name' => basename($path),
            'size' => Storage::disk('local')->size($path),
            'url' => route('gestion.participants.documents.download', [
                'participant' => $participantId,
                'filename' => basename($path),
            ]),
        ])
        ->toArray();
}
```

Pass documents to the edit modal when opening it (only if `$canSeeSensible`).

- [ ] **Step 2: Update Blade view — add documents section**

In the edit modal, after the medical data section (guarded by `peut_voir_donnees_sensibles`):

```blade
@if ($canSeeSensible && count($editDocuments ?? []) > 0)
    <h6 class="mt-3">Documents joints</h6>
    <ul class="list-group list-group-sm">
        @foreach ($editDocuments as $doc)
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="small">{{ $doc['name'] }} ({{ number_format($doc['size'] / 1024, 0) }} Ko)</span>
                <a href="{{ $doc['url'] }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-download"></i>
                </a>
            </li>
        @endforeach
    </ul>
@endif
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/ParticipantTable.php resources/views/livewire/participant-table.blade.php
git commit -m "feat(formulaire): display uploaded documents in participant edit modal"
```

---

### Task 7: Full test suite + Pint + verification

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 3: Commit style fixes if any**

- [ ] **Step 4: Run fresh migration to verify**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`

- [ ] **Step 5: Manual smoke test**

Verify in browser:
- Navigate to Gestion > Opérations > select operation > Participants tab
- Click link icon on a participant → token modal appears with code and URL
- Copy URL → open in incognito (no auth) → form loads with greeting + coordinates pre-filled
- Fill medical data + attach a document → confirm in modal → submit
- "Merci" message appears, token no longer works
- Back in admin: badge shows "Rempli", document visible in edit modal
- Go to `/formulaire` → enter code manually → "déjà rempli" message
