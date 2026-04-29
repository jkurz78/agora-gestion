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

    public function render(): View
    {
        return view('livewire.setup.setup-form');
    }
}
