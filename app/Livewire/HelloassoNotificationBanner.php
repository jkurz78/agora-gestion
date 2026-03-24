<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\HelloAssoNotification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoNotificationBanner extends Component
{
    public bool $showDetails = false;

    public function toggleDetails(): void
    {
        $this->showDetails = ! $this->showDetails;
    }

    public function render(): View
    {
        $notifications = HelloAssoNotification::where('association_id', 1)
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.helloasso-notification-banner', [
            'notifications' => $notifications,
            'count' => $notifications->count(),
        ]);
    }
}
