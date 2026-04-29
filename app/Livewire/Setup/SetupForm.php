<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use Illuminate\View\View;
use Livewire\Component;

final class SetupForm extends Component
{
    public string $prenom = '';

    public string $nom = '';

    public string $email = '';

    public string $password = '';

    public string $nomAsso = '';

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'prenom' => 'required|string|max:50',
            'nom' => 'required|string|max:50',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'nomAsso' => 'required|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => "L'email n'est pas valide.",
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'nomAsso.required' => 'Le nom de l\'association est obligatoire.',
        ];
    }

    public function submit(): void
    {
        $this->validate();

        // L'implémentation réelle vient en Task 5.
    }

    public function render(): View
    {
        return view('livewire.setup.setup-form');
    }
}
