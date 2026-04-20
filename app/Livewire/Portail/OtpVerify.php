<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Services\Portail\OtpService;
use App\Services\Portail\VerifyStatus;
use Illuminate\View\View;
use Livewire\Component;

final class OtpVerify extends Component
{
    use WithPortailTenant;

    public Association $association;

    public string $code = '';

    public ?string $errorMessage = null;

    public ?string $infoMessage = null;

    public function mount(Association $association): void
    {
        $this->association = $association;

        if (! session()->has('portail.pending_email')) {
            $this->redirectRoute('portail.login', ['association' => $association->slug]);
        }
    }

    public function submit(OtpService $otp, AuthSessionService $authSession): void
    {
        $this->validate(
            rules: ['code' => 'required|string'],
            messages: ['code.required' => 'Veuillez saisir votre code.'],
        );

        $email = (string) session('portail.pending_email');
        $cleanCode = preg_replace('/\s+/', '', $this->code) ?? '';

        $result = $otp->verify($this->association, $email, $cleanCode);

        if ($result->status === VerifyStatus::Cooldown) {
            $this->errorMessage = sprintf(
                'Trop de tentatives. Réessayez dans %d minutes.',
                (int) config('portail.otp_cooldown_minutes'),
            );

            return;
        }

        if ($result->status === VerifyStatus::Invalid) {
            $this->errorMessage = 'Code invalide ou expiré.';

            return;
        }

        $this->handleSuccess($result->tiersIds, $authSession);
    }

    /** @param list<int> $tiersIds */
    private function handleSuccess(array $tiersIds, AuthSessionService $authSession): void
    {
        session()->forget('portail.pending_email');

        if (count($tiersIds) === 1) {
            $authSession->loginSingleTiers(Tiers::findOrFail($tiersIds[0]));
            $this->redirectRoute('portail.home', ['association' => $this->association->slug]);

            return;
        }

        // Multi-Tiers → chooser
        $authSession->markPendingTiers($tiersIds);
        $this->redirectRoute('portail.choisir', ['association' => $this->association->slug]);
    }

    public function resend(OtpService $otp): void
    {
        $email = (string) session('portail.pending_email');

        if (! $otp->canResend($this->association, $email)) {
            $remaining = (int) config('portail.otp_resend_seconds');
            $this->errorMessage = sprintf(
                'Veuillez patienter encore %d secondes avant de demander un nouveau code.',
                $remaining,
            );

            return;
        }

        $otp->request($this->association, $email);
        $this->infoMessage = 'Un nouveau code vous a été envoyé.';
        $this->errorMessage = null;
    }

    public function render(): View
    {
        return view('livewire.portail.otp-verify')
            ->layout('portail.layouts.app', ['contentClass' => 'col-md-6 col-lg-5']);
    }
}
