# 2FA Security Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add optional two-factor authentication (OTP email + TOTP app) to the login flow, configurable per user from the profile page.

**Architecture:** TwoFactorService handles all 2FA logic (code generation, verification, trusted browser cookies). EnsureTwoFactor middleware intercepts authenticated requests when 2FA is active but not yet validated. TwoFactorSetup Livewire component manages activation/deactivation from the profile page.

**Tech Stack:** Laravel 11, Livewire 4, pragmarx/google2fa-laravel, bacon/bacon-qr-code, Pest PHP

---

## Task 1: Install packages and create enum + migration

**Files:**
- Create: `app/Enums/TwoFactorMethod.php`
- Create: migration `add_two_factor_to_users`
- Create: migration `create_two_factor_codes_table`
- Modify: `app/Models/User.php` — add fillable, casts
- Test: `tests/Unit/TwoFactorMethodEnumTest.php`

- [ ] **Step 1: Install google2fa package**

```bash
./vendor/bin/sail composer require pragmarx/google2fa-laravel
```

- [ ] **Step 2: Create the TwoFactorMethod enum**

Create `app/Enums/TwoFactorMethod.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TwoFactorMethod: string
{
    case Email = 'email';
    case Totp = 'totp';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'OTP par email',
            self::Totp => 'Application (TOTP)',
        };
    }
}
```

- [ ] **Step 3: Write enum test**

Create `tests/Unit/TwoFactorMethodEnumTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;

it('has two cases', function () {
    expect(TwoFactorMethod::cases())->toHaveCount(2);
});

it('provides French labels', function () {
    expect(TwoFactorMethod::Email->label())->toBe('OTP par email');
    expect(TwoFactorMethod::Totp->label())->toBe('Application (TOTP)');
});
```

- [ ] **Step 4: Create migration for users table**

Run: `./vendor/bin/sail artisan make:migration add_two_factor_to_users`

Content:

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable()->after('role');
            $table->text('two_factor_secret')->nullable()->after('two_factor_method');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            $table->string('two_factor_trusted_token', 64)->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_method',
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_recovery_codes',
                'two_factor_trusted_token',
            ]);
        });
    }
};
```

- [ ] **Step 5: Create two_factor_codes table migration**

Run: `./vendor/bin/sail artisan make:migration create_two_factor_codes_table`

Content:

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
        Schema::create('two_factor_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_codes');
    }
};
```

- [ ] **Step 6: Update User model**

In `app/Models/User.php`:

Add import:
```php
use App\Enums\TwoFactorMethod;
```

Add to `$fillable`:
```php
'two_factor_method',
'two_factor_secret',
'two_factor_confirmed_at',
'two_factor_recovery_codes',
'two_factor_trusted_token',
```

Add to `casts()`:
```php
'two_factor_method' => TwoFactorMethod::class,
'two_factor_secret' => 'encrypted',
'two_factor_confirmed_at' => 'datetime',
'two_factor_recovery_codes' => 'encrypted:array',
```

Add helper method:
```php
public function hasTwoFactorEnabled(): bool
{
    if ($this->two_factor_method === null) {
        return false;
    }

    if ($this->two_factor_method === TwoFactorMethod::Totp) {
        return $this->two_factor_confirmed_at !== null;
    }

    return true;
}
```

- [ ] **Step 7: Run migrations and tests**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail exec laravel.test php artisan test tests/Unit/TwoFactorMethodEnumTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/Enums/TwoFactorMethod.php app/Models/User.php database/migrations/*two_factor* tests/Unit/TwoFactorMethodEnumTest.php composer.json composer.lock
git commit -m "feat(2fa): add TwoFactorMethod enum, migrations, and google2fa package"
```

---

## Task 2: TwoFactorService

**Files:**
- Create: `app/Services/TwoFactorService.php`
- Create: `app/Mail/TwoFactorCodeMail.php`
- Create: `resources/views/emails/two-factor-code.blade.php`
- Test: `tests/Feature/Services/TwoFactorServiceTest.php`

- [ ] **Step 1: Create the Mailable**

Create `app/Mail/TwoFactorCodeMail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre code de vérification',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.two-factor-code',
        );
    }
}
```

Create `resources/views/emails/two-factor-code.blade.php`:

```blade
<x-mail::message>
# Code de vérification

Votre code de connexion est :

<x-mail::panel>
<strong style="font-size: 24px; letter-spacing: 4px;">{{ $code }}</strong>
</x-mail::panel>

Ce code expire dans **10 minutes**.

Si vous n'avez pas demandé ce code, ignorez cet email.

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
```

- [ ] **Step 2: Create TwoFactorService**

Create `app/Services/TwoFactorService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Response;

final class TwoFactorService
{
    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    // ── Enable / Disable ──────────────────────────────────────

    public function enableEmail(User $user): void
    {
        $user->update([
            'two_factor_method' => TwoFactorMethod::Email,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function enableTotp(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->update([
            'two_factor_method' => TwoFactorMethod::Totp,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ]);

        return $secret;
    }

    public function confirmTotp(User $user, string $code): bool
    {
        if ($user->two_factor_method !== TwoFactorMethod::Totp || $user->two_factor_secret === null) {
            return false;
        }

        if (! $this->google2fa->verifyKey($user->two_factor_secret, $code)) {
            return false;
        }

        $user->update(['two_factor_confirmed_at' => now()]);

        return true;
    }

    public function disable(User $user): void
    {
        $user->update([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_trusted_token' => null,
        ]);
    }

    // ── Email OTP ─────────────────────────────────────────────

    public function generateEmailCode(User $user): void
    {
        // Clear old codes
        DB::table('two_factor_codes')->where('user_id', $user->id)->delete();

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('two_factor_codes')->insert([
            'user_id' => $user->id,
            'code' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user)->send(new TwoFactorCodeMail($plainCode));
    }

    public function verifyEmailCode(User $user, string $code): bool
    {
        $record = DB::table('two_factor_codes')
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($record === null) {
            return false;
        }

        if (! Hash::check($code, $record->code)) {
            return false;
        }

        DB::table('two_factor_codes')->where('user_id', $user->id)->delete();

        return true;
    }

    // ── TOTP ──────────────────────────────────────────────────

    public function verifyTotpCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        return $this->google2fa->verifyKey($user->two_factor_secret, $code);
    }

    // ── Recovery codes ────────────────────────────────────────

    public function generateRecoveryCodes(User $user): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < 8; $i++) {
            $code = Str::lower(Str::random(4).'-'.Str::random(4));
            $plainCodes[] = $code;
            $hashedCodes[] = Hash::make($code);
        }

        $user->update(['two_factor_recovery_codes' => $hashedCodes]);

        return $plainCodes;
    }

    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $hashedCodes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashedCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                unset($hashedCodes[$index]);
                $user->update(['two_factor_recovery_codes' => array_values($hashedCodes)]);

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    // ── Trusted browser ───────────────────────────────────────

    public function setTrustedBrowser(Response $response, User $user): Response
    {
        if ($user->two_factor_trusted_token === null) {
            $user->update(['two_factor_trusted_token' => Str::random(64)]);
        }

        $cookieValue = encrypt($user->id.'|'.$user->two_factor_trusted_token);

        $cookie = Cookie::make(
            'two_factor_trusted',
            $cookieValue,
            60 * 24 * 30, // 30 days
            '/',
            null,
            true,  // secure
            true,  // httpOnly
        );

        $response->headers->setCookie($cookie);

        return $response;
    }

    public function isTrustedBrowser(Request $request, User $user): bool
    {
        $cookie = $request->cookie('two_factor_trusted');

        if ($cookie === null || $user->two_factor_trusted_token === null) {
            return false;
        }

        try {
            $decrypted = decrypt($cookie);
            [$userId, $token] = explode('|', $decrypted, 2);

            return (int) $userId === $user->id && $token === $user->two_factor_trusted_token;
        } catch (\Throwable) {
            return false;
        }
    }

    public function revokeTrustedBrowsers(User $user): void
    {
        $user->update(['two_factor_trusted_token' => null]);
    }

    // ── QR Code ───────────────────────────────────────────────

    public function qrCodeSvg(User $user): string
    {
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $user->two_factor_secret,
        );

        $renderer = new \BaconQrCode\Renderer\Image\SvgImageBackEnd();
        $imageRenderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(200),
            $renderer,
        );
        $writer = new \BaconQrCode\Writer($imageRenderer);

        return $writer->writeString($qrCodeUrl);
    }
}
```

- [ ] **Step 3: Write the tests**

Create `tests/Feature/Services/TwoFactorServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(TwoFactorService::class);
});

// ── Enable / Disable ──

it('enables email 2FA', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Email);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('enables TOTP and returns secret', function () {
    $user = User::factory()->create();
    $secret = $this->service->enableTotp($user);

    expect($secret)->toBeString()->toHaveLength(16);
    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Totp);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse(); // not confirmed yet
});

it('confirms TOTP with valid code', function () {
    $user = User::factory()->create();
    $secret = $this->service->enableTotp($user);

    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    $validCode = $google2fa->getCurrentOtp($secret);

    expect($this->service->confirmTotp($user, $validCode))->toBeTrue();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejects invalid TOTP confirmation code', function () {
    $user = User::factory()->create();
    $this->service->enableTotp($user);

    expect($this->service->confirmTotp($user, '000000'))->toBeFalse();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('disables 2FA', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);
    $this->service->disable($user);

    expect($user->fresh()->two_factor_method)->toBeNull();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// ── Email OTP ──

it('generates and verifies email code', function () {
    Mail::fake();
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    $this->service->generateEmailCode($user);

    Mail::assertSent(TwoFactorCodeMail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email);
    });
});

it('rejects expired email code', function () {
    $user = User::factory()->create();
    $this->service->enableEmail($user);

    // Insert expired code
    \DB::table('two_factor_codes')->insert([
        'user_id' => $user->id,
        'code' => \Hash::make('123456'),
        'expires_at' => now()->subMinute(),
    ]);

    expect($this->service->verifyEmailCode($user, '123456'))->toBeFalse();
});

// ── Recovery codes ──

it('generates 8 recovery codes', function () {
    $user = User::factory()->create();
    $codes = $this->service->generateRecoveryCodes($user);

    expect($codes)->toHaveCount(8);
    expect($codes[0])->toMatch('/^[a-z0-9]{4}-[a-z0-9]{4}$/');
});

it('verifies and consumes a recovery code', function () {
    $user = User::factory()->create();
    $codes = $this->service->generateRecoveryCodes($user);

    expect($this->service->verifyRecoveryCode($user, $codes[0]))->toBeTrue();
    expect($this->service->remainingRecoveryCodes($user->fresh()))->toBe(7);
});

it('rejects invalid recovery code', function () {
    $user = User::factory()->create();
    $this->service->generateRecoveryCodes($user);

    expect($this->service->verifyRecoveryCode($user, 'xxxx-yyyy'))->toBeFalse();
});

// ── Trusted browser ──

it('revokes all trusted browsers', function () {
    $user = User::factory()->create(['two_factor_trusted_token' => 'old-token']);
    $this->service->revokeTrustedBrowsers($user);

    expect($user->fresh()->two_factor_trusted_token)->toBeNull();
});
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Services/TwoFactorServiceTest.php
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/sail exec laravel.test php artisan test
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/TwoFactorService.php app/Mail/TwoFactorCodeMail.php resources/views/emails/two-factor-code.blade.php tests/Feature/Services/TwoFactorServiceTest.php
git commit -m "feat(2fa): add TwoFactorService with email OTP, TOTP, recovery codes, trusted browser"
```

---

## Task 3: EnsureTwoFactor middleware + challenge routes and controller

**Files:**
- Create: `app/Http/Middleware/EnsureTwoFactor.php`
- Create: `app/Http/Controllers/Auth/TwoFactorChallengeController.php`
- Create: `resources/views/auth/two-factor-challenge.blade.php`
- Modify: `routes/auth.php` — add 2FA routes
- Modify: `routes/web.php` — add middleware to route groups
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — trigger email code after login
- Test: `tests/Feature/Auth/TwoFactorChallengeTest.php`

- [ ] **Step 1: Create the middleware**

Create `app/Http/Middleware/EnsureTwoFactor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\TwoFactorService;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTwoFactor
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($this->twoFactorService->isTrustedBrowser($request, $user)) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_verified') === true) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
```

- [ ] **Step 2: Create the challenge controller**

Create `app/Http/Controllers/Auth/TwoFactorChallengeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('home');
        }

        if ($user->two_factor_method === TwoFactorMethod::Email && ! $request->session()->has('two_factor_code_sent')) {
            $this->twoFactorService->generateEmailCode($user);
            $request->session()->put('two_factor_code_sent', true);
        }

        return view('auth.two-factor-challenge', [
            'method' => $user->two_factor_method,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        $code = $request->input('code');
        $useRecovery = $request->boolean('use_recovery');
        $verified = false;

        if ($useRecovery) {
            $verified = $this->twoFactorService->verifyRecoveryCode($user, $code);
        } elseif ($user->two_factor_method === TwoFactorMethod::Email) {
            $verified = $this->twoFactorService->verifyEmailCode($user, $code);
        } elseif ($user->two_factor_method === TwoFactorMethod::Totp) {
            $verified = $this->twoFactorService->verifyTotpCode($user, $code);
        }

        if (! $verified) {
            return back()->withErrors(['code' => 'Le code est invalide ou expiré.']);
        }

        $request->session()->put('two_factor_verified', true);
        $request->session()->forget('two_factor_code_sent');

        $response = redirect()->intended(route('home'));

        if ($request->boolean('trust_browser')) {
            $this->twoFactorService->setTrustedBrowser($response, $user);
        }

        return $response;
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_method === TwoFactorMethod::Email) {
            $this->twoFactorService->generateEmailCode($user);
        }

        return back()->with('status', 'Un nouveau code a été envoyé.');
    }
}
```

- [ ] **Step 3: Create the challenge blade view**

Create `resources/views/auth/two-factor-challenge.blade.php`:

```blade
<x-guest-layout>
    <h5 class="mb-3 text-center">Vérification en deux étapes</h5>

    @if ($method === \App\Enums\TwoFactorMethod::Email)
        <p class="text-muted small mb-3">Un code a été envoyé à votre adresse email.</p>
    @else
        <p class="text-muted small mb-3" id="totp-message">Entrez le code de votre application d'authentification.</p>
        <p class="text-muted small mb-3 d-none" id="recovery-message">Entrez un de vos codes de récupération.</p>
    @endif

    @if (session('status'))
        <div class="alert alert-success small">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('two-factor.challenge.verify') }}" id="challenge-form">
        @csrf
        <input type="hidden" name="use_recovery" id="use_recovery" value="0">

        <div class="mb-3">
            <label for="code" class="form-label" id="code-label">Code</label>
            <input id="code" type="text" name="code"
                   class="form-control @error('code') is-invalid @enderror"
                   required autofocus autocomplete="one-time-code"
                   inputmode="numeric" maxlength="20">
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="trust_browser" value="1" id="trust_browser">
            <label for="trust_browser" class="form-check-label small">Se fier à ce navigateur pendant 30 jours</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-shield-check"></i> Vérifier
        </button>
    </form>

    <div class="d-flex justify-content-between">
        @if ($method === \App\Enums\TwoFactorMethod::Email)
            <form method="POST" action="{{ route('two-factor.challenge.resend') }}">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0">Renvoyer le code</button>
            </form>
        @else
            <button type="button" class="btn btn-link btn-sm p-0" id="toggle-recovery"
                    onclick="
                        var isRecovery = document.getElementById('use_recovery').value === '1';
                        document.getElementById('use_recovery').value = isRecovery ? '0' : '1';
                        document.getElementById('totp-message').classList.toggle('d-none');
                        document.getElementById('recovery-message').classList.toggle('d-none');
                        document.getElementById('code').setAttribute('inputmode', isRecovery ? 'numeric' : 'text');
                        document.getElementById('code').setAttribute('maxlength', isRecovery ? '6' : '20');
                        this.textContent = isRecovery ? 'Utiliser un code de récupération' : 'Utiliser le code TOTP';
                        document.getElementById('code').value = '';
                        document.getElementById('code').focus();
                    ">
                Utiliser un code de récupération
            </button>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-link btn-sm p-0 text-muted">Se déconnecter</button>
        </form>
    </div>
</x-guest-layout>
```

- [ ] **Step 4: Add routes**

In `routes/auth.php`, add inside the `Route::middleware('auth')` group (before the closing `});`):

```php
    // Two-factor authentication challenge
    Route::get('two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'store'])
        ->name('two-factor.challenge.verify');
    Route::post('two-factor/challenge/resend', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('two-factor.challenge.resend');
```

- [ ] **Step 5: Add EnsureTwoFactor middleware to route groups**

In `routes/web.php`, add the middleware to both route groups. Change:

```php
Route::middleware(['auth', 'verified', DetecteEspace::class.':compta'])
```

To:

```php
Route::middleware(['auth', 'verified', \App\Http\Middleware\EnsureTwoFactor::class, DetecteEspace::class.':compta'])
```

Same for the gestion group:

```php
Route::middleware(['auth', 'verified', \App\Http\Middleware\EnsureTwoFactor::class, DetecteEspace::class.':gestion'])
```

- [ ] **Step 6: Trigger email code on login for email 2FA users**

In `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, modify `store()`:

```php
public function store(LoginRequest $request): RedirectResponse
{
    $request->authenticate();

    $request->session()->regenerate();

    $user = $request->user();

    if ($user->hasTwoFactorEnabled() && $user->two_factor_method === \App\Enums\TwoFactorMethod::Email) {
        app(\App\Services\TwoFactorService::class)->generateEmailCode($user);
        $request->session()->put('two_factor_code_sent', true);
    }

    return redirect()->intended(route('home', absolute: false));
}
```

- [ ] **Step 7: Write tests**

Create `tests/Feature/Auth/TwoFactorChallengeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('redirects to challenge when 2FA email is active', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->get(route('compta.dashboard'))
        ->assertRedirect(route('two-factor.challenge'));
});

it('allows access when 2FA is not active', function () {
    $user = User::factory()->create(['two_factor_method' => null]);

    $this->actingAs($user)
        ->get(route('compta.dashboard'))
        ->assertOk();
});

it('allows access after successful 2FA verification', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->withSession(['two_factor_verified' => true])
        ->get(route('compta.dashboard'))
        ->assertOk();
});

it('shows challenge page for email method', function () {
    Mail::fake();
    $user = User::factory()->create(['two_factor_method' => TwoFactorMethod::Email, 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('Un code a été envoyé');
});

it('shows challenge page for TOTP method', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('application d\'authentification');
});

it('verifies valid TOTP code', function () {
    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    $secret = $google2fa->generateSecretKey();

    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
    ]);

    $validCode = $google2fa->getCurrentOtp($secret);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), ['code' => $validCode])
        ->assertRedirect(route('home'));
});

it('rejects invalid code', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), ['code' => '000000'])
        ->assertSessionHasErrors('code');
});

it('verifies recovery code for TOTP', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ]);

    $service = app(TwoFactorService::class);
    $codes = $service->generateRecoveryCodes($user);

    $this->actingAs($user)
        ->post(route('two-factor.challenge.verify'), [
            'code' => $codes[0],
            'use_recovery' => '1',
        ])
        ->assertRedirect(route('home'));
});
```

- [ ] **Step 8: Run tests**

```bash
./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Auth/TwoFactorChallengeTest.php
```

- [ ] **Step 9: Run full suite**

```bash
./vendor/bin/sail exec laravel.test php artisan test
```

- [ ] **Step 10: Commit**

```bash
git add app/Http/Middleware/EnsureTwoFactor.php app/Http/Controllers/Auth/TwoFactorChallengeController.php app/Http/Controllers/Auth/AuthenticatedSessionController.php resources/views/auth/two-factor-challenge.blade.php routes/auth.php routes/web.php tests/Feature/Auth/TwoFactorChallengeTest.php
git commit -m "feat(2fa): add EnsureTwoFactor middleware, challenge controller, and login flow"
```

---

## Task 4: TwoFactorSetup Livewire component (profile page)

**Files:**
- Create: `app/Livewire/TwoFactorSetup.php`
- Create: `resources/views/livewire/two-factor-setup.blade.php`
- Modify: `resources/views/profil/index.blade.php` — include the component
- Test: `tests/Feature/Livewire/TwoFactorSetupTest.php`

- [ ] **Step 1: Create the Livewire component**

Create `app/Livewire/TwoFactorSetup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TwoFactorMethod;
use App\Services\TwoFactorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class TwoFactorSetup extends Component
{
    public ?string $method = null;

    public ?string $totpSecret = null;

    public ?string $qrCodeSvg = null;

    public string $confirmCode = '';

    public ?array $recoveryCodes = null;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->method = $user->two_factor_method?->value;
    }

    public function enableEmail(): void
    {
        $service = app(TwoFactorService::class);
        $service->enableEmail(Auth::user());
        $this->method = 'email';
        $this->successMessage = 'Vérification par email activée. Un code vous sera envoyé à chaque connexion.';
    }

    public function startTotp(): void
    {
        $service = app(TwoFactorService::class);
        $user = Auth::user();
        $this->totpSecret = $service->enableTotp($user);
        $this->qrCodeSvg = $service->qrCodeSvg($user->fresh());
        $this->method = 'totp';
    }

    public function confirmTotp(): void
    {
        $this->validate(['confirmCode' => ['required', 'string', 'size:6']]);

        $service = app(TwoFactorService::class);
        $user = Auth::user();

        if (! $service->confirmTotp($user, $this->confirmCode)) {
            $this->errorMessage = 'Code invalide. Vérifiez votre application et réessayez.';

            return;
        }

        $this->recoveryCodes = $service->generateRecoveryCodes($user);
        $this->totpSecret = null;
        $this->qrCodeSvg = null;
        $this->confirmCode = '';
        $this->successMessage = 'TOTP activé. Sauvegardez vos codes de récupération ci-dessous.';
    }

    public function regenerateRecoveryCodes(): void
    {
        $service = app(TwoFactorService::class);
        $this->recoveryCodes = $service->generateRecoveryCodes(Auth::user());
        $this->successMessage = 'Nouveaux codes de récupération générés. Les anciens sont invalidés.';
    }

    public function revokeTrustedBrowsers(): void
    {
        $service = app(TwoFactorService::class);
        $service->revokeTrustedBrowsers(Auth::user());
        $this->successMessage = 'Tous les appareils de confiance ont été révoqués.';
    }

    public function disable(): void
    {
        $service = app(TwoFactorService::class);
        $service->disable(Auth::user());
        $this->method = null;
        $this->totpSecret = null;
        $this->qrCodeSvg = null;
        $this->recoveryCodes = null;
        $this->successMessage = 'Vérification en deux étapes désactivée.';
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.two-factor-setup', [
            'isConfirmed' => $user->two_factor_confirmed_at !== null,
            'remainingCodes' => app(TwoFactorService::class)->remainingRecoveryCodes($user),
        ]);
    }
}
```

- [ ] **Step 2: Create the blade view**

Create `resources/views/livewire/two-factor-setup.blade.php`:

```blade
<div>
    @if ($successMessage)
        <div class="alert alert-success alert-dismissible">
            {{ $successMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="alert alert-danger alert-dismissible">
            {{ $errorMessage }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card mt-4">
        <div class="card-header">Vérification en deux étapes</div>
        <div class="card-body">

            @if ($method === null)
                {{-- ═══ Disabled state ═══ --}}
                <p class="text-muted">La vérification en deux étapes n'est pas activée.</p>
                <div class="d-flex gap-2">
                    <button wire:click="enableEmail" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-envelope"></i> Activer via email
                    </button>
                    <button wire:click="startTotp" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-phone"></i> Activer via application
                    </button>
                </div>

            @elseif ($method === 'email')
                {{-- ═══ Email OTP active ═══ --}}
                <p><span class="badge bg-success">OTP email activé</span></p>
                <p class="text-muted small">Un code à 6 chiffres vous sera envoyé par email à chaque connexion.</p>
                <div class="d-flex gap-2">
                    <button wire:click="startTotp" class="btn btn-outline-secondary btn-sm">
                        Passer au TOTP (application)
                    </button>
                    <button wire:click="disable" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Désactiver la vérification en deux étapes ?')">
                        Désactiver
                    </button>
                </div>

            @elseif ($method === 'totp' && $totpSecret !== null && ! $isConfirmed)
                {{-- ═══ TOTP setup (not yet confirmed) ═══ --}}
                <p class="mb-2">Scannez ce QR code avec votre application d'authentification :</p>

                <div class="text-center my-3">
                    {!! $qrCodeSvg !!}
                </div>

                <p class="small text-muted text-center">
                    Ou entrez manuellement : <code>{{ $totpSecret }}</code>
                </p>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-auto">
                        <label class="form-label">Code de vérification</label>
                        <input type="text" wire:model="confirmCode" class="form-control"
                               inputmode="numeric" maxlength="6" placeholder="000000"
                               wire:keydown.enter="confirmTotp">
                        @error('confirmCode') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-auto">
                        <button wire:click="confirmTotp" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Confirmer
                        </button>
                    </div>
                    <div class="col-auto">
                        <button wire:click="disable" class="btn btn-outline-secondary">Annuler</button>
                    </div>
                </div>

            @elseif ($method === 'totp' && $isConfirmed)
                {{-- ═══ TOTP active and confirmed ═══ --}}
                <p><span class="badge bg-success">TOTP activé</span></p>
                <p class="text-muted small">Votre application d'authentification génère les codes de connexion.</p>

                @if ($recoveryCodes)
                    <div class="alert alert-warning">
                        <strong><i class="bi bi-exclamation-triangle"></i> Sauvegardez ces codes de récupération</strong>
                        <p class="small mb-2">Chaque code ne peut être utilisé qu'une seule fois. Conservez-les en lieu sûr.</p>
                        <div class="row">
                            @foreach ($recoveryCodes as $code)
                                <div class="col-6 col-md-3"><code>{{ $code }}</code></div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="small text-muted">
                        <i class="bi bi-key"></i> {{ $remainingCodes }} code(s) de récupération restant(s)
                    </p>
                @endif

                <div class="d-flex gap-2 flex-wrap">
                    <button wire:click="regenerateRecoveryCodes" class="btn btn-outline-secondary btn-sm"
                            onclick="return confirm('Régénérer les codes ? Les anciens seront invalidés.')">
                        <i class="bi bi-arrow-repeat"></i> Régénérer les codes
                    </button>
                    <button wire:click="revokeTrustedBrowsers" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-laptop"></i> Révoquer les appareils de confiance
                    </button>
                    <button wire:click="disable" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('Désactiver la vérification en deux étapes ?')">
                        Désactiver
                    </button>
                </div>
            @endif

        </div>
    </div>
</div>
```

- [ ] **Step 3: Include component in profile page**

In `resources/views/profil/index.blade.php`, change:

```blade
<x-app-layout>
    <h1 class="mb-4">Mon profil</h1>
    <livewire:mon-profil />
</x-app-layout>
```

To:

```blade
<x-app-layout>
    <h1 class="mb-4">Mon profil</h1>
    <livewire:mon-profil />
    <livewire:two-factor-setup />
</x-app-layout>
```

- [ ] **Step 4: Write tests**

Create `tests/Feature/Livewire/TwoFactorSetupTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\TwoFactorMethod;
use App\Livewire\TwoFactorSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders disabled state when 2FA is off', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->assertSee('pas activée')
        ->assertSee('Activer via email')
        ->assertSee('Activer via application');
});

it('can enable email 2FA', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('enableEmail')
        ->assertSet('method', 'email')
        ->assertSee('OTP email activé');

    expect($user->fresh()->two_factor_method)->toBe(TwoFactorMethod::Email);
});

it('can start TOTP setup and shows QR code', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $component->assertSet('method', 'totp')
        ->assertSee('Scannez ce QR code');

    expect($component->get('totpSecret'))->toBeString()->not->toBeEmpty();
    expect($component->get('qrCodeSvg'))->toContain('<svg');
});

it('can confirm TOTP with valid code', function () {
    $user = User::factory()->create();
    $google2fa = new \PragmaRX\Google2FA\Google2FA();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $secret = $component->get('totpSecret');
    $validCode = $google2fa->getCurrentOtp($secret);

    $component->set('confirmCode', $validCode)
        ->call('confirmTotp')
        ->assertSee('TOTP activé');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('shows recovery codes after TOTP confirmation', function () {
    $user = User::factory()->create();
    $google2fa = new \PragmaRX\Google2FA\Google2FA();

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    $secret = $component->get('totpSecret');
    $validCode = $google2fa->getCurrentOtp($secret);

    $component->set('confirmCode', $validCode)
        ->call('confirmTotp');

    $codes = $component->get('recoveryCodes');
    expect($codes)->toHaveCount(8);
});

it('can disable 2FA', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('disable')
        ->assertSet('method', null)
        ->assertSee('pas activée');

    expect($user->fresh()->two_factor_method)->toBeNull();
});

it('can regenerate recovery codes', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Totp,
        'two_factor_secret' => 'test-secret',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['old-code-hash'],
    ]);

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('regenerateRecoveryCodes');

    expect($component->get('recoveryCodes'))->toHaveCount(8);
});

it('can switch from email to TOTP', function () {
    $user = User::factory()->create([
        'two_factor_method' => TwoFactorMethod::Email,
        'two_factor_confirmed_at' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(TwoFactorSetup::class)
        ->call('startTotp');

    expect($component->get('totpSecret'))->not->toBeNull();
    expect($component->get('qrCodeSvg'))->toContain('<svg');
});
```

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/sail exec laravel.test php artisan test tests/Feature/Livewire/TwoFactorSetupTest.php
```

- [ ] **Step 6: Run full suite**

```bash
./vendor/bin/sail exec laravel.test php artisan test
```

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/TwoFactorSetup.php resources/views/livewire/two-factor-setup.blade.php resources/views/profil/index.blade.php tests/Feature/Livewire/TwoFactorSetupTest.php
git commit -m "feat(2fa): add TwoFactorSetup Livewire component on profile page"
```

---

## Task 5: Final Pint + full test suite

- [ ] **Step 1: Run Pint**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint
```

- [ ] **Step 2: Run full test suite**

```bash
./vendor/bin/sail exec laravel.test php artisan test
```

- [ ] **Step 3: Commit Pint fixes if any**

```bash
git add -A && git commit -m "style: apply Pint formatting after 2FA implementation" || echo "Nothing to commit"
```
