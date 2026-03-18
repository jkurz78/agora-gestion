<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionLigne;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class TransactionService
{
    public function create(array $data, array $lignes): Transaction
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par']    = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $transaction = Transaction::create($data);
            foreach ($lignes as $ligne) {
                $transaction->lignes()->create($ligne);
            }
            return $transaction;
        });
    }

    public function update(Transaction $transaction, array $data, array $lignes): Transaction
    {
        return DB::transaction(function () use ($transaction, $data, $lignes) {
            $transaction->load(['rapprochement' => fn ($q) => $q->lockForUpdate()]);

            if ($transaction->isLockedByRapprochement()) {
                $this->assertLockedInvariants($transaction, $data, $lignes);
            }

            $transaction->update($data);

            if ($transaction->isLockedByRapprochement()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'sous_categorie_id' => $ligneData['sous_categorie_id'],
                        'operation_id'      => $ligneData['operation_id'],
                        'seance'            => $ligneData['seance'],
                        'notes'             => $ligneData['notes'],
                    ]);
                }
            } else {
                $affectationsSnapshot = [];
                foreach ($lignes as $ligneData) {
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null) {
                        $existingLigne = $transaction->lignes()->where('id', $oldId)->first();
                        if ($existingLigne === null) {
                            continue;
                        }
                        $oldCents = (int) round((float) $existingLigne->montant * 100);
                        $newCents = (int) round((float) $ligneData['montant'] * 100);
                        if ($oldCents !== $newCents) {
                            continue;
                        }
                        $aff = $existingLigne->affectations()->get();
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
                $transaction->lignes()->forceDelete();
                foreach ($lignes as $ligneData) {
                    $newLigne = $transaction->lignes()->create($ligneData);
                    $oldId    = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
                    if ($oldId !== null && isset($affectationsSnapshot[$oldId])) {
                        foreach ($affectationsSnapshot[$oldId] as $affData) {
                            $newLigne->affectations()->create($affData);
                        }
                    }
                }
            }

            return $transaction->fresh();
        });
    }

    public function delete(Transaction $transaction): void
    {
        if ($transaction->rapprochement_id !== null) {
            throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être supprimée.');
        }
        DB::transaction(function () use ($transaction) {
            $transaction->lignes()->each(function (TransactionLigne $ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });
            $transaction->delete();
        });
    }

    public function affecterLigne(TransactionLigne $ligne, array $affectations): void
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
            $ligne->affectations()->delete();
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

    public function supprimerAffectations(TransactionLigne $ligne): void
    {
        DB::transaction(fn () => $ligne->affectations()->delete());
    }

    private function assertLockedInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ($transaction->date->format('Y-m-d') !== $data['date']) {
            throw new \RuntimeException('La date ne peut pas être modifiée sur une transaction rapprochée.');
        }
        if ((int) $transaction->compte_id !== (int) $data['compte_id']) {
            throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une transaction rapprochée.');
        }
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction rapprochée.');
        }
        $existingLignes = $transaction->lignes()->get()->keyBy('id');
        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction rapprochée.');
        }
        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une transaction rapprochée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction rapprochée.');
            }
        }
    }
}
