<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class OperationDetail extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function render(): View
    {
        return view('livewire.operation-detail');
    }
}
