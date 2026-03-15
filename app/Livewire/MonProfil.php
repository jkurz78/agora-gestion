<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class MonProfil extends Component
{
    public string $nom = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public ?string $successMessage = null;

    public function mount(): void
    {
        $user = Auth::user();
        $this->nom = $user->nom;
        $this->email = $user->email;
    }

    public function save(): void
    {
        $user = Auth::user();

        $this->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', "unique:users,email,{$user->id}"],
            'password' => ['nullable', 'confirmed', 'min:8'],
        ]);

        $emailChanged = $this->email !== $user->email;

        $user->nom = $this->nom;

        if ($emailChanged) {
            $user->email = $this->email;
            // Reset email verification since email changed
            if (array_key_exists('email_verified_at', $user->getAttributes())) {
                $user->email_verified_at = null;
            }
        }

        if ($this->password !== '') {
            // 'password' cast is 'hashed' — assign plain text, cast handles hashing
            $user->password = $this->password;
        }

        $user->save();

        if ($emailChanged && $user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        $this->password = '';
        $this->password_confirmation = '';

        $this->successMessage = $emailChanged
            ? 'Profil mis à jour. Vérifiez votre nouvelle adresse email pour la confirmer.'
            : 'Profil mis à jour.';
    }

    public function render(): View
    {
        return view('livewire.mon-profil');
    }
}
