<?php

declare(strict_types=1);

namespace App\Http\Resources\Portail;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

final class TransactionDepensePubliqueResource extends JsonResource
{
    private string $associationSlug = '';

    public function withAssociationSlug(string $slug): static
    {
        $this->associationSlug = $slug;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'date_piece' => $this->resource->date->format('Y-m-d'),
            'reference' => $this->resource->numero_piece ?? $this->resource->libelle,
            'montant_ttc' => (float) $this->resource->montant_total,
            'statut_reglement' => $this->resource->statut_reglement->isEncaisse() ? 'Réglée' : 'En attente',
            'pdf_url' => $this->buildPdfUrl(),
        ];
    }

    private function buildPdfUrl(): ?string
    {
        if (! $this->resource->hasPieceJointe()) {
            return null;
        }

        return URL::signedRoute('portail.historique.pdf', [
            'association' => $this->associationSlug,
            'transaction' => $this->resource->id,
        ]);
    }
}
