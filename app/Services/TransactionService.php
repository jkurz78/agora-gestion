<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class TransactionService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function create(array $data, array $lignes): Transaction
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        $this->validateInscriptionRequiresOperation($lignes);

        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            // Dépenses : payées au moment de la saisie → recu par défaut
            if (! isset($data['statut_reglement'])) {
                $data['statut_reglement'] = ($data['type'] ?? '') === 'depense' ? 'recu' : 'en_attente';
            }
            $transaction = Transaction::create($data);
            foreach ($lignes as $ligne) {
                $transaction->lignes()->create($ligne);
            }

            return $transaction;
        });
    }

    public function update(Transaction $transaction, array $data, array $lignes): Transaction
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        $this->validateInscriptionRequiresOperation($lignes);

        return DB::transaction(function () use ($transaction, $data, $lignes) {
            $transaction->load(['rapprochement' => fn ($q) => $q->lockForUpdate()]);

            if ($transaction->isLockedByRemise()) {
                throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être modifiée.');
            }

            if ($transaction->isLockedByFacture()) {
                $this->assertLockedByFactureInvariants($transaction, $data, $lignes);
            }

            if ($transaction->isLockedByRapprochement()) {
                $this->assertLockedInvariants($transaction, $data, $lignes);
            }

            $transaction->update($data);

            if ($transaction->isLockedByFacture()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'notes' => $ligneData['notes'],
                    ]);
                }
            } elseif ($transaction->isLockedByRapprochement()) {
                foreach ($lignes as $ligneData) {
                    $transaction->lignes()->where('id', $ligneData['id'])->update([
                        'sous_categorie_id' => $ligneData['sous_categorie_id'],
                        'operation_id' => $ligneData['operation_id'],
                        'seance' => $ligneData['seance'],
                        'notes' => $ligneData['notes'],
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
                                'seance' => $a->seance,
                                'montant' => $a->montant,
                                'notes' => $a->notes,
                            ])->toArray();
                        }
                    }
                }
                $transaction->lignes()->forceDelete();
                foreach ($lignes as $ligneData) {
                    $newLigne = $transaction->lignes()->create($ligneData);
                    $oldId = isset($ligneData['id']) && $ligneData['id'] !== null ? (int) $ligneData['id'] : null;
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
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->rapprochement_id !== null) {
            throw new \RuntimeException('Cette transaction est pointée dans un rapprochement et ne peut pas être supprimée.');
        }
        if ($transaction->isLockedByRemise()) {
            throw new \RuntimeException('Cette transaction est liée à une remise bancaire et ne peut pas être supprimée.');
        }
        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée et ne peut pas être supprimée.');
        }
        DB::transaction(function () use ($transaction) {
            // Supprimer la pièce jointe si présente
            if ($transaction->hasPieceJointe()) {
                $this->deletePieceJointe($transaction);
            }

            $transaction->lignes()->each(function (TransactionLigne $ligne) {
                $ligne->affectations()->delete();
                $ligne->delete();
            });
            $transaction->delete();
        });
    }

    public function affecterLigne(TransactionLigne $ligne, array $affectations): void
    {
        $transaction = $ligne->transaction;
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée. La ventilation ne peut pas être modifiée.');
        }

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
                    'seance' => $a['seance'] ?: null,
                    'montant' => $a['montant'],
                    'notes' => $a['notes'] ?: null,
                ]);
            }
        });
    }

    public function supprimerAffectations(TransactionLigne $ligne): void
    {
        $transaction = $ligne->transaction;
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
        );

        if ($transaction->isLockedByFacture()) {
            throw new \RuntimeException('Cette transaction est liée à une facture validée. La ventilation ne peut pas être modifiée.');
        }

        DB::transaction(fn () => $ligne->affectations()->delete());
    }

    public function storePieceJointe(Transaction $transaction, UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé : '.$mime);
        }

        $dir = "pieces-jointes/{$transaction->id}";

        if ($transaction->piece_jointe_path !== null) {
            Storage::disk('local')->deleteDirectory($dir);
        }

        $extension = $file->guessExtension() ?? 'bin';
        $storedPath = $file->storeAs($dir, "justificatif.{$extension}", 'local');

        $transaction->update([
            'piece_jointe_path' => $storedPath,
            'piece_jointe_nom' => $file->getClientOriginalName(),
            'piece_jointe_mime' => $mime,
        ]);
    }

    public function storePieceJointeFromPath(
        Transaction $transaction,
        string $sourcePath,
        string $originalFilename,
        string $mime,
    ): void {
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé : '.$mime);
        }

        if (! file_exists($sourcePath)) {
            throw new \InvalidArgumentException('Fichier source introuvable : '.$sourcePath);
        }

        $dir = "pieces-jointes/{$transaction->id}";

        if ($transaction->piece_jointe_path !== null) {
            Storage::disk('local')->deleteDirectory($dir);
        }

        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'bin';
        $storedPath = "{$dir}/justificatif.{$extension}";

        Storage::disk('local')->put($storedPath, file_get_contents($sourcePath));

        $transaction->update([
            'piece_jointe_path' => $storedPath,
            'piece_jointe_nom' => $originalFilename,
            'piece_jointe_mime' => $mime,
        ]);
    }

    public function deletePieceJointe(Transaction $transaction): void
    {
        if ($transaction->piece_jointe_path === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory("pieces-jointes/{$transaction->id}");

        $transaction->update([
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
        ]);
    }

    private function validateInscriptionRequiresOperation(array $lignes): void
    {
        $inscriptionSousCategorieIds = SousCategorie::where('pour_inscriptions', true)
            ->pluck('id')
            ->toArray();

        foreach ($lignes as $index => $ligne) {
            if (in_array((int) $ligne['sous_categorie_id'], $inscriptionSousCategorieIds, true)
                && empty($ligne['operation_id'])) {
                throw new \InvalidArgumentException(
                    "La ligne {$index} utilise une sous-catégorie d'inscription : operation_id est obligatoire."
                );
            }
        }
    }

    private function assertLockedByFactureInvariants(Transaction $transaction, array $data, array $lignes): void
    {
        if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction facturée.');
        }
        $existingLignes = $transaction->lignes()->get()->keyBy('id');
        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction facturée.');
        }
        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue sur une transaction facturée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction facturée.');
            }
            if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
                throw new \RuntimeException('La sous-catégorie ne peut pas être modifiée sur une transaction facturée.');
            }
            $existingOpId = $existing->operation_id;
            $newOpId = $ligneData['operation_id'] !== '' && $ligneData['operation_id'] !== null ? (int) $ligneData['operation_id'] : null;
            if ($existingOpId !== $newOpId) {
                throw new \RuntimeException('L\'opération ne peut pas être modifiée sur une transaction facturée.');
            }
            $existingSeance = $existing->seance;
            $newSeance = isset($ligneData['seance']) && $ligneData['seance'] !== '' && $ligneData['seance'] !== null ? (int) $ligneData['seance'] : null;
            if ($existingSeance !== $newSeance) {
                throw new \RuntimeException('La séance ne peut pas être modifiée sur une transaction facturée.');
            }
        }
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
