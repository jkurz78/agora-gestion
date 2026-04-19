<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Services\Portail\OtpService;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class Login extends Component
{
    use WithPortailTenant;

    public Association $association;

    #[Validate('required|email|max:255')]
    public string $email = '';

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function submit(OtpService $otp): void
    {
        $this->validate();

        $otp->request($this->association, $this->email);

        session([
            'portail.pending_email' => mb_strtolower($this->email),
        ]);
        session()->flash(
            'portail.info',
            'Si votre adresse est reconnue, un code à 8 chiffres vous a été envoyé.',
        );

        $this->redirectRoute('portail.otp', ['association' => $this->association->slug]);
    }

    protected function messages(): array
    {
        return [
            'email.required' => 'Veuillez saisir votre adresse email.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
        ];
    }

    public function render(): View
    {
        return view('livewire.portail.login')
            ->layout('portail.layouts.app');
    }
}
