<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Depense;
use App\Models\DepenseLigne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DepenseService
{
    public function create(array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $depense = Depense::create($data);
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }

            return $depense;
        });
    }

    public function update(Depense $depense, array $data, array $lignes): Depense
    {
        return DB::transaction(function () use ($depense, $data, $lignes) {
            $depense->load(['rapprochement' => fn ($q) => $q->lockForUpdate()]);

            if ($depense->isLockedByRapprochement()) {
                $this->assertLockedInvariants($depense, $data, $lignes);
            }

            $depense->update($data);

            if ($depense->isLockedByRapprochement()) {
                foreach ($lignes as $ligneData) {
                    $depense->lignes()->where('id', $ligneData['id'])->update([
                        'operation_id' => $ligneData['operation_id'],
                        'seance'       => $ligneData['seance'],
                        'notes'        => $ligneData['notes'],
                    ]);
                }
            } else {
                // Pièce non verrouillée : snapshot des affectations avant suppression
                $affectationsSnapshot = [];
                foreach ($lignes as $ligneData) {
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null) {
                        $aff = $depense->lignes()->where('id', $oldId)->first()?->affectations()->get() ?? collect();
                        if ($aff->isNotEmpty()) {
                            $affectationsSnapshot[$oldId] = $aff->map(fn ($a) => [
                                'operation_id' => $a->operation_id,
                                'seance'       => $a->seance,
                                'montant'      => $a->montant,
                                'notes'        => $a->notes,
                            ])->toArray();
                        }
                    }
                }

                $depense->lignes()->forceDelete();
                foreach ($lignes as $ligneData) {
                    $newLigne = $depense->lignes()->create($ligneData);
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null && isset($affectationsSnapshot[$oldId])) {
                        foreach ($affectationsSnapshot[$oldId] as $affData) {
                            $newLigne->affectations()->create($affData);
                        }
                    }
                }
            }

            return $depense->fresh();
        });
    }

    private function assertLockedInvariants(Depense $depense, array $data, array $lignes): void
    {
        if ($depense->date->format('Y-m-d') !== $data['date']) {
            throw new \RuntimeException('La date ne peut pas être modifiée sur une dépense rapprochée.');
        }

        if ((int) $depense->compte_id !== (int) $data['compte_id']) {
            throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une dépense rapprochée.');
        }

        if ((int) round((float) $depense->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une dépense rapprochée.');
        }

        $existingLignes = $depense->lignes()->get()->keyBy('id');

        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une dépense rapprochée.');
        }

        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une dépense rapprochée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une dépense rapprochée.');
            }
            if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
                throw new \RuntimeException('La sous-catégorie d\'une ligne ne peut pas être modifiée sur une dépense rapprochée.');
            }
        }
    }

    public function affecterLigne(DepenseLigne $ligne, array $affectations): void
    {
        DB::transaction(function () use ($ligne, $affectations) {
            if (count($affectations) === 0) {
                throw new \RuntimeException('La liste des affectations ne peut pas être vide.');
            }

            $total = 0;
            foreach ($affectations as $a) {
                if ((int) round((float) ($a['montant'] ?? 0) * 100) <= 0) {
                    throw new \RuntimeException('Chaque affectation doit avoir un montant positif.');
                }
                $total += (int) round((float) $a['montant'] * 100);
            }

            $attendu = (int) round((float) $ligne->montant * 100);
            if ($total !== $attendu) {
                throw new \RuntimeException(
                    "La somme des affectations ({$total} centimes) ne correspond pas au montant de la ligne ({$attendu} centimes)."
                );
            }

            $ligne->affectations()->forceDelete();
            foreach ($affectations as $a) {
                $ligne->affectations()->create([
                    'operation_id' => $a['operation_id'] ?: null,
                    'seance'       => $a['seance'] ?: null,
                    'montant'      => $a['montant'],
                    'notes'        => $a['notes'] ?: null,
                ]);
            }
        });
    }

    public function supprimerAffectations(DepenseLigne $ligne): void
    {
        DB::transaction(function () use ($ligne) {
            $ligne->affectations()->forceDelete();
        });
    }

    public function delete(Depense $depense): void
    {
        if ($depense->rapprochement_id !== null) {
            throw new \RuntimeException('Cette dépense est pointée dans un rapprochement et ne peut pas être supprimée.');
        }

        DB::transaction(function () use ($depense) {
            $depense->lignes()->delete();
            $depense->delete();
        });
    }
}
