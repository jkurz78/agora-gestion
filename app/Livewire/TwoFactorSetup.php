<?php

declare(strict_types=1);

namespace App\Livewire;

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
