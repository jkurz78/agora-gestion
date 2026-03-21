<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use Illuminate\View\View;
use Livewire\Component;

final class HelloAssoForm extends Component
{
    public function render(): View
    {
        return view('livewire.parametres.helloasso-form');
    }
}
