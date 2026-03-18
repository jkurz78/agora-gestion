<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Database\Seeder;

/**
 * Seed de test : 60 dépenses + 20 recettes janvier–février 2026
 * sur le compte courant (id=1), avec les tiers, opérations et sous-catégories existants.
 */
class TransactionsJanFevSeeder extends Seeder
{
    // IDs présents en base localhost
    private int $compteId = 1;

    /** @var int[] */
    private array $tiersIds = [1, 2, 3, 4, 5, 6];

    /** @var int[] */
    private array $operationIds = [1, 2];

    /** @var int[] */
    private array $sousCatsDepense = [10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26];

    /** @var int[] */
    private array $sousCatsRecette = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    /** @var string[] */
    private array $modes = ['virement', 'cheque', 'especes', 'cb', 'prelevement'];

    /** @var string[] */
    private array $libellesDepense = [
        'Location salle janvier', 'Fournitures bureau', 'Frais bancaires',
        'Repas équipe', 'Transport participants', 'Honoraires formateur',
        'Supervision mensuelle', 'Petit équipement', 'Frais déplacement',
        'Animation séance', 'Location centre équestre', 'Bilan pré-thérapeutique',
        'Hébergement serveur', 'Logiciel gestion', 'Achat matériel',
        'Restauration groupe', 'Frais postaux', 'Sessions inter-ateliers',
        'Développement site', 'Réparation équipement',
    ];

    /** @var string[] */
    private array $libellesRecette = [
        'Parcours thérapeutique janv.', 'Formation groupe', 'Subvention trimestrielle',
        'Vente produits', 'Prestation encadrement', 'Don reçu',
        'Intérêts compte', 'Cotisation membre', 'Abandon de créance',
        'Recette exceptionnelle',
    ];

    public function run(): void
    {
        $userId = \App\Models\User::first()?->id ?? 1;

        // ── 60 dépenses ───────────────────────────────────────────────────────
        for ($i = 0; $i < 60; $i++) {
            $date = $this->randomDate();
            $montant = $this->randomMontant(50, 2500);
            $scId = $this->pick($this->sousCatsDepense);
            $opId = $i % 5 === 0 ? null : $this->pick($this->operationIds); // ~80% avec opération

            $tx = Transaction::create([
                'type' => TypeTransaction::Depense->value,
                'date' => $date,
                'libelle' => $this->pick($this->libellesDepense).' '.($i + 1),
                'montant_total' => $montant,
                'mode_paiement' => $this->pick($this->modes),
                'tiers_id' => $i % 4 === 0 ? null : $this->pick($this->tiersIds),
                'reference' => 'REF-D-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'compte_id' => $this->compteId,
                'pointe' => false,
                'saisi_par' => $userId,
            ]);

            TransactionLigne::create([
                'transaction_id' => $tx->id,
                'sous_categorie_id' => $scId,
                'operation_id' => $opId,
                'seance' => $opId !== null && $i % 3 === 0 ? rand(1, 3) : null,
                'montant' => $montant,
            ]);
        }

        // ── 20 recettes ───────────────────────────────────────────────────────
        for ($i = 0; $i < 20; $i++) {
            $date = $this->randomDate();
            $montant = $this->randomMontant(100, 5000);
            $scId = $this->pick($this->sousCatsRecette);
            $opId = $i % 4 === 0 ? null : $this->pick($this->operationIds); // ~75% avec opération

            $tx = Transaction::create([
                'type' => TypeTransaction::Recette->value,
                'date' => $date,
                'libelle' => $this->pick($this->libellesRecette).' '.($i + 1),
                'montant_total' => $montant,
                'mode_paiement' => $this->pick($this->modes),
                'tiers_id' => $i % 3 === 0 ? null : $this->pick($this->tiersIds),
                'reference' => 'REF-R-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'compte_id' => $this->compteId,
                'pointe' => false,
                'saisi_par' => $userId,
            ]);

            TransactionLigne::create([
                'transaction_id' => $tx->id,
                'sous_categorie_id' => $scId,
                'operation_id' => $opId,
                'seance' => $opId !== null && $i % 3 === 0 ? rand(1, 3) : null,
                'montant' => $montant,
            ]);
        }
    }

    /** @param  array<mixed>  $arr */
    private function pick(array $arr): mixed
    {
        return $arr[array_rand($arr)];
    }

    private function randomDate(): string
    {
        // Janvier ou février 2026
        $month = rand(0, 1) === 0 ? '01' : '02';
        $maxDay = $month === '02' ? 28 : 31;

        return '2026-'.$month.'-'.str_pad((string) rand(1, $maxDay), 2, '0', STR_PAD_LEFT);
    }

    private function randomMontant(int $min, int $max): float
    {
        return round(rand($min * 100, $max * 100) / 100, 2);
    }
}
