<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait WithPerPage
{
    public int $perPage = 20;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function effectivePerPage(): int
    {
        return $this->perPage === 0 ? PHP_INT_MAX : $this->perPage;
    }
}
