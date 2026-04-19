<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

final class NoteDeFraisPolicy
{
    /**
     * Le "user" sur la garde tiers-portail est un Tiers (qui implémente Authenticatable).
     * On accepte Authenticatable|null pour satisfaire la signature que Laravel découvre,
     * puis on vérifie que c'est bien un Tiers.
     */
    public function view(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        return (int) $user->id === (int) $noteDeFrais->tiers_id;
    }

    public function update(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        return (int) $user->id === (int) $noteDeFrais->tiers_id;
    }

    public function delete(?Authenticatable $user, NoteDeFrais $noteDeFrais): Response|bool
    {
        if (! $user instanceof Tiers) {
            return false;
        }

        if ((int) $user->id !== (int) $noteDeFrais->tiers_id) {
            return false;
        }

        // Seul un brouillon peut être supprimé
        return $noteDeFrais->statut === StatutNoteDeFrais::Brouillon;
    }
}
