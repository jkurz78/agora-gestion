<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RemiseMultiSourceTestSeeder extends Seeder
{
    public function run(): void
    {
        $exercice = now()->month >= 9 ? now()->year : now()->year - 1;
        $user = User::first();

        // ── Comptes ──────────────────────────────────────────────────────────
        $creances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
        $compteCourant = CompteBancaire::where('est_systeme', false)->first();

        // ── Tiers ────────────────────────────────────────────────────────────
        $dupont = Tiers::firstOrCreate(
            ['nom' => 'DUPONT', 'type' => 'particulier', 'prenom' => 'Marie'],
            [
                'type' => 'particulier',
                'nom' => 'DUPONT',
                'prenom' => 'Marie',
                'pour_depenses' => false,
                'pour_recettes' => true,
                'est_helloasso' => false,
            ],
        );

        $martin = Tiers::firstOrCreate(
            ['nom' => 'MARTIN', 'type' => 'particulier', 'prenom' => 'Paul'],
            [
                'type' => 'particulier',
                'nom' => 'MARTIN',
                'prenom' => 'Paul',
                'pour_depenses' => false,
                'pour_recettes' => true,
                'est_helloasso' => false,
            ],
        );

        $garcia = Tiers::firstOrCreate(
            ['nom' => 'GARCIA', 'type' => 'particulier', 'prenom' => 'Sofia'],
            [
                'type' => 'particulier',
                'nom' => 'GARCIA',
                'prenom' => 'Sofia',
                'pour_depenses' => false,
                'pour_recettes' => true,
                'est_helloasso' => false,
            ],
        );

        // ── SousCategorie & TypeOperation ────────────────────────────────────
        $sousFormation = SousCategorie::where('nom', 'Formations')->first()
            ?? SousCategorie::where('pour_inscriptions', true)->first()
            ?? SousCategorie::first();

        $typePhoto = TypeOperation::firstOrCreate(
            ['nom' => 'Stage Photo'],
            [
                'nom' => 'Stage Photo',
                'libelle_article' => 'le stage photo',
                'description' => 'Stage photo toutes niveaux.',
                'sous_categorie_id' => $sousFormation?->id,
                'nombre_seances' => 4,
                'reserve_adherents' => false,
                'actif' => true,
                'formulaire_actif' => false,
                'formulaire_prescripteur' => false,
                'formulaire_parcours_therapeutique' => false,
                'formulaire_droit_image' => false,
            ],
        );

        // ── Opération ─────────────────────────────────────────────────────────
        $operation = Operation::firstOrCreate(
            ['nom' => 'Stage Photo Printemps 2026'],
            [
                'nom' => 'Stage Photo Printemps 2026',
                'statut' => 'en_cours',
                'type_operation_id' => $typePhoto->id,
                'nombre_seances' => 2,
                'date_debut' => '2026-04-01',
                'date_fin' => '2026-04-30',
            ],
        );

        // ── Séances ───────────────────────────────────────────────────────────
        $seance1 = Seance::firstOrCreate(
            ['operation_id' => $operation->id, 'numero' => 1],
            [
                'operation_id' => $operation->id,
                'numero' => 1,
                'date' => '2026-04-05',
                'titre' => 'Séance 1 – Prise de vue',
            ],
        );

        $seance2 = Seance::firstOrCreate(
            ['operation_id' => $operation->id, 'numero' => 2],
            [
                'operation_id' => $operation->id,
                'numero' => 2,
                'date' => '2026-04-12',
                'titre' => 'Séance 2 – Tirage et retouche',
            ],
        );

        // ── Participants ──────────────────────────────────────────────────────
        $participantDupont = Participant::firstOrCreate(
            ['tiers_id' => $dupont->id, 'operation_id' => $operation->id],
            [
                'tiers_id' => $dupont->id,
                'operation_id' => $operation->id,
                'date_inscription' => '2026-03-20',
            ],
        );

        $participantMartin = Participant::firstOrCreate(
            ['tiers_id' => $martin->id, 'operation_id' => $operation->id],
            [
                'tiers_id' => $martin->id,
                'operation_id' => $operation->id,
                'date_inscription' => '2026-03-22',
            ],
        );

        // ── Règlements séance (flux séance) ───────────────────────────────────
        Reglement::firstOrCreate(
            ['participant_id' => $participantDupont->id, 'seance_id' => $seance1->id],
            [
                'participant_id' => $participantDupont->id,
                'seance_id' => $seance1->id,
                'mode_paiement' => ModePaiement::Cheque,
                'montant_prevu' => 50.00,
            ],
        );

        Reglement::firstOrCreate(
            ['participant_id' => $participantDupont->id, 'seance_id' => $seance2->id],
            [
                'participant_id' => $participantDupont->id,
                'seance_id' => $seance2->id,
                'mode_paiement' => ModePaiement::Cheque,
                'montant_prevu' => 75.00,
            ],
        );

        Reglement::firstOrCreate(
            ['participant_id' => $participantMartin->id, 'seance_id' => $seance1->id],
            [
                'participant_id' => $participantMartin->id,
                'seance_id' => $seance1->id,
                'mode_paiement' => ModePaiement::Especes,
                'montant_prevu' => 30.00,
            ],
        );

        // ── Transactions "Créances à recevoir" ────────────────────────────────
        // Chèque 120€ Dupont — "Vente matériel photo"
        $txDupont = $this->firstOrCreateTransaction(
            $creances,
            $dupont,
            ModePaiement::Cheque,
            120.00,
            'Vente matériel photo',
            '2026-04-03',
            $exercice,
            $sousFormation?->id,
            $user,
        );

        // Espèces 45€ Martin — "Tirage photo grand format"
        $txMartin = $this->firstOrCreateTransaction(
            $creances,
            $martin,
            ModePaiement::Especes,
            45.00,
            'Tirage photo grand format',
            '2026-04-05',
            $exercice,
            $sousFormation?->id,
            $user,
        );

        // Virement 200€ Garcia — "Inscription formation en ligne"
        $txGarcia = $this->firstOrCreateTransaction(
            $creances,
            $garcia,
            ModePaiement::Virement,
            200.00,
            'Inscription formation en ligne',
            '2026-04-06',
            $exercice,
            $sousFormation?->id,
            $user,
        );

        // ── Factures validées ─────────────────────────────────────────────────
        $this->firstOrCreateFactureValidee(
            $txDupont,
            $dupont,
            $compteCourant,
            $exercice,
            $user,
        );

        $this->firstOrCreateFactureValidee(
            $txGarcia,
            $garcia,
            $compteCourant,
            $exercice,
            $user,
        );
    }

    private function firstOrCreateTransaction(
        CompteBancaire $compte,
        Tiers $tiers,
        ModePaiement $mode,
        float $montant,
        string $libelle,
        string $date,
        int $exercice,
        ?int $sousCategorieId,
        User $user,
    ): Transaction {
        $existing = Transaction::where('compte_id', $compte->id)
            ->where('tiers_id', $tiers->id)
            ->where('libelle', $libelle)
            ->where('montant_total', $montant)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($compte, $tiers, $mode, $montant, $libelle, $date, $sousCategorieId, $user): Transaction {
            $transaction = Transaction::create([
                'type' => TypeTransaction::Recette,
                'date' => $date,
                'libelle' => $libelle,
                'montant_total' => $montant,
                'mode_paiement' => $mode,
                'tiers_id' => $tiers->id,
                'compte_id' => $compte->id,
                'pointe' => false,
                'saisi_par' => $user->id,
            ]);

            TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'sous_categorie_id' => $sousCategorieId,
                'montant' => $montant,
            ]);

            return $transaction;
        });
    }

    private function firstOrCreateFactureValidee(
        Transaction $transaction,
        Tiers $tiers,
        ?CompteBancaire $compteCourant,
        int $exercice,
        User $user,
    ): Facture {
        // Check if this transaction is already linked to a non-annulée facture
        $existing = $transaction->factures()
            ->where('statut', '!=', StatutFacture::Annulee->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($transaction, $tiers, $compteCourant, $exercice, $user): Facture {
            // Determine next sequential numero for this exercice
            $maxSeq = Facture::where('exercice', $exercice)
                ->whereIn('statut', [StatutFacture::Validee, StatutFacture::Annulee])
                ->whereNotNull('numero')
                ->lockForUpdate()
                ->get()
                ->map(fn ($f) => (int) last(explode('-', $f->numero)))
                ->max() ?? 0;

            $numero = sprintf('F-%d-%04d', $exercice, $maxSeq + 1);

            $montantTotal = (float) $transaction->lignes()->sum('montant');

            $facture = Facture::create([
                'numero' => $numero,
                'date' => $transaction->date->toDateString(),
                'statut' => StatutFacture::Validee,
                'tiers_id' => $tiers->id,
                'compte_bancaire_id' => $compteCourant?->id,
                'conditions_reglement' => 'Payable à réception',
                'mentions_legales' => "TVA non applicable, art. 261-7-1° du CGI",
                'montant_total' => $montantTotal,
                'saisi_par' => $user->id,
                'exercice' => $exercice,
            ]);

            // Link transaction to facture via pivot
            $facture->transactions()->attach($transaction->id);

            // Create FactureLigne records from TransactionLignes
            $ordre = 0;
            foreach ($transaction->lignes as $ligne) {
                $ordre++;
                FactureLigne::create([
                    'facture_id' => $facture->id,
                    'transaction_ligne_id' => $ligne->id,
                    'type' => TypeLigneFacture::Montant,
                    'libelle' => $ligne->sousCategorie?->nom ?? $transaction->libelle,
                    'montant' => $ligne->montant,
                    'ordre' => $ordre,
                ]);
            }

            return $facture;
        });
    }
}
