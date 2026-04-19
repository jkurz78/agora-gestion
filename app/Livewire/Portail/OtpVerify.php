<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Models\Association;
use App\Services\Portail\OtpService;
use App\Services\Portail\VerifyStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class OtpVerify extends Component
{
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

    public function submit(OtpService $otp): void
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

        // Success
        $tiersIds = $result->tiersIds;

        if (count($tiersIds) === 1) {
            Auth::guard('tiers-portail')->loginUsingId($tiersIds[0]);
            session()->forget('portail.pending_email');
            $this->redirectRoute('portail.home', ['association' => $this->association->slug]);

            return;
        }

        // Multi-Tiers → chooser
        session([
            'portail.pending_tiers_ids' => array_map('intval', $tiersIds),
        ]);
        session()->forget('portail.pending_email');
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
            ->layout('portail.layouts.app');
    }
}
