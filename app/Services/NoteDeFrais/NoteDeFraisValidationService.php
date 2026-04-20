<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NoteDeFraisValidationService
{
    /**
     * Rejette une note de frais soumise avec un motif obligatoire.
     *
     * @throws ValidationException si le motif est vide
     * @throws DomainException si la NDF n'est pas en statut Soumise
     */
    public function rejeter(NoteDeFrais $ndf, string $motif): void
    {
        $validator = Validator::make(
            ['motif' => $motif],
            ['motif' => ['required', 'string', 'min:1']],
            [
                'motif.required' => 'Le motif est obligatoire.',
                'motif.min' => 'Le motif est obligatoire.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($ndf->statut !== StatutNoteDeFrais::Soumise) {
            throw new DomainException(
                sprintf(
                    'Seule une NDF soumise peut être rejetée (statut actuel : %s).',
                    $ndf->statut->label()
                )
            );
        }

        $ndf->update([
            'statut' => StatutNoteDeFrais::Rejetee->value,
            'motif_rejet' => $motif,
        ]);

        Log::info('comptabilite.ndf.rejected', [
            'ndf_id' => $ndf->id,
            'tiers_id' => $ndf->tiers_id,
            'motif' => $motif,
        ]);
    }
}
