<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Livewire\Component;

final class AssociationSelector extends Component
{
    public function select(int $associationId): mixed
    {
        $user = auth()->user();
        abort_if($user === null, 401);

        $asso = $user->associations()->where('association_id', $associationId)->first();
        abort_if($asso === null, 403, 'Association inaccessible.');

        session(['current_association_id' => $asso->id]);
        $user->update(['derniere_association_id' => $asso->id]);
        session()->regenerate();

        return $this->redirectRoute('dashboard', navigate: false);
    }

    public function render(): mixed
    {
        $user = auth()->user();
        $associations = $user?->associations()->whereNull('association_user.revoked_at')->get() ?? collect();

        return view('livewire.auth.association-selector', compact('associations'));
    }
}
