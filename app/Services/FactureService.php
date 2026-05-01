<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExtournePayload;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Support\CurrentAssociation;
use App\Tenant\TenantContext;
use Atgp\FacturX\Writer as FacturXWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class FactureService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly TransactionExtourneService $extourneService,
    ) {}

    /**
     * Create a new brouillon facture for the given tiers.
     */
    public function creer(int $tiersId): Facture
    {
        $exercice = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($exercice);

        return DB::transaction(function () use ($tiersId, $exercice): Facture {
            $association = CurrentAssociation::get();

            $mentionsLegales = $association?->facture_mentions_legales
                ?? "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé";

            $conditionsReglement = $association?->facture_conditions_reglement
                ?? 'Payable à réception';

            $compteBancaireId = $association?->facture_compte_bancaire_id;

            return Facture::create([
                'numero' => null,
                'date' => now()->toDateString(),
                'statut' => StatutFacture::Brouillon,
                'tiers_id' => $tiersId,
                'compte_bancaire_id' => $compteBancaireId,
                'conditions_reglement' => $conditionsReglement,
                'mentions_legales' => $mentionsLegales,
                'montant_total' => 0,
                'saisi_par' => auth()->id(),
                'exercice' => $exercice,
            ]);
        });
    }

    /**
     * Crée une facture brouillon vierge sans devis source (facture manuelle directe).
     *
     * Aucune ligne n'est créée. Le numéro reste null (statut brouillon).
     * Le tiers doit appartenir à l'association courante (guard multi-tenant).
     *
     * @throws \RuntimeException si le tiers n'appartient pas à l'association courante
     *                           ou si TenantContext n'est pas booté
     */
    public function creerManuelleVierge(int $tiersId): Facture
    {
        $exercice = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($exercice);

        return DB::transaction(function () use ($tiersId, $exercice): Facture {
            $association = CurrentAssociation::get();

            // Guard multi-tenant : charge le tiers sans scope pour pouvoir
            // détecter les cross-tenant, puis vérifie l'appartenance.
            $tiers = Tiers::withoutGlobalScopes()->find($tiersId);

            if ($tiers === null || (int) $tiers->association_id !== (int) TenantContext::currentId()) {
                throw new \RuntimeException("Accès interdit : ce tiers n'appartient pas à votre association.");
            }

            $mentionsLegales = $association->facture_mentions_legales
                ?? "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé";

            $conditionsReglement = $association->facture_conditions_reglement
                ?? 'Payable à réception';

            $compteBancaireId = $association->facture_compte_bancaire_id;

            return Facture::create([
                'numero' => null,
                'date' => now()->toDateString(),
                'statut' => StatutFacture::Brouillon,
                'tiers_id' => $tiersId,
                'devis_id' => null,
                'mode_paiement_prevu' => null,
                'compte_bancaire_id' => $compteBancaireId,
                'conditions_reglement' => $conditionsReglement,
                'mentions_legales' => $mentionsLegales,
                'montant_total' => 0,
                'saisi_par' => auth()->id(),
                'exercice' => $exercice,
            ]);
        });
    }

    /**
     * Attach transactions to a brouillon facture and generate facture lignes from their transaction lignes.
     *
     * @param  array<int>  $transactionIds
     */
    public function ajouterTransactions(Facture $facture, array $transactionIds): void
    {
        $this->assertBrouillon($facture);

        DB::transaction(function () use ($facture, $transactionIds): void {
            $transactions = Transaction::with(['lignes.sousCategorie', 'lignes.operation'])
                ->whereIn('id', $transactionIds)
                ->get();

            // Validate all transactions
            foreach ($transactions as $transaction) {
                if ($transaction->type !== TypeTransaction::Recette) {
                    throw new \RuntimeException("La transaction #{$transaction->id} n'est pas une recette.");
                }

                if ((int) $transaction->tiers_id !== (int) $facture->tiers_id) {
                    throw new \RuntimeException("La transaction #{$transaction->id} n'appartient pas au même tiers que la facture.");
                }

                // Check if already linked to a non-annulée facture
                $existingFacture = $transaction->factures()
                    ->where('statut', '!=', StatutFacture::Annulee->value)
                    ->first();

                if ($existingFacture !== null) {
                    throw new \RuntimeException("La transaction #{$transaction->id} est déjà liée à une facture non annulée.");
                }
            }

            // Attach to pivot
            $facture->transactions()->attach($transactionIds);

            // Get current max ordre
            $maxOrdre = (int) FactureLigne::where('facture_id', $facture->id)->max('ordre');

            // Create facture lignes from transaction lignes
            foreach ($transactions as $transaction) {
                foreach ($transaction->lignes as $ligne) {
                    $maxOrdre++;

                    FactureLigne::create([
                        'facture_id' => $facture->id,
                        'transaction_ligne_id' => $ligne->id,
                        'type' => TypeLigneFacture::Montant,
                        'libelle' => $this->genererLibelleLigne($ligne),
                        'montant' => $ligne->montant,
                        'ordre' => $maxOrdre,
                    ]);
                }
            }
        });
    }

    /**
     * Remove a transaction from a brouillon facture, deleting corresponding facture lignes.
     */
    public function retirerTransaction(Facture $facture, int $transactionId): void
    {
        $this->assertBrouillon($facture);

        DB::transaction(function () use ($facture, $transactionId): void {
            $transaction = Transaction::with('lignes')->findOrFail($transactionId);

            $ligneIds = $transaction->lignes->pluck('id')->toArray();

            // Delete facture lignes linked to this transaction's lignes
            FactureLigne::where('facture_id', $facture->id)
                ->whereIn('transaction_ligne_id', $ligneIds)
                ->delete();

            // Detach from pivot
            $facture->transactions()->detach($transactionId);
        });
    }

    /**
     * Delete a brouillon facture with all its lignes and pivot entries.
     */
    public function supprimerBrouillon(Facture $facture): void
    {
        $this->assertBrouillon($facture);

        DB::transaction(function () use ($facture): void {
            // Delete all lignes
            FactureLigne::where('facture_id', $facture->id)->delete();

            // Detach all transactions from pivot
            $facture->transactions()->detach();

            // Delete the facture
            $facture->delete();
        });
    }

    /**
     * Validate a brouillon facture: assign sequential numero, freeze montant_total, set statut to validee.
     * If the facture carries ≥ 1 MontantManuel line, generates 1 Transaction recette + N TransactionLignes.
     */
    public function valider(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            throw new \RuntimeException('Seul un brouillon peut être validé.');
        }

        // Compte les lignes ayant un impact comptable (Montant ref OU MontantManuel)
        $lignesAvecMontant = $facture->lignes()
            ->whereIn('type', [TypeLigneFacture::Montant->value, TypeLigneFacture::MontantManuel->value])
            ->count();
        if ($lignesAvecMontant === 0) {
            throw new \RuntimeException('La facture doit contenir au moins une ligne avec montant.');
        }

        // Guards spécifiques aux factures portant ≥ 1 ligne MontantManuel
        $this->assertGuardsLignesManuelles($facture);

        $transactionGeneree = DB::transaction(function () use ($facture): ?Transaction {
            // Re-lock the facture itself to protect against double-validation race
            $factureVerrouillee = Facture::lockForUpdate()->find($facture->id);

            if ($factureVerrouillee === null || $factureVerrouillee->statut !== StatutFacture::Brouillon) {
                throw new \RuntimeException('Seul un brouillon peut être validé.');
            }

            // Check exercice is open inside the transaction
            $this->exerciceService->assertOuvert($factureVerrouillee->exercice);

            // Lock ALL factures of this exercice upfront (single lock for both
            // chronological constraint and sequential numbering)
            $exerciceFactures = Facture::where('exercice', $factureVerrouillee->exercice)
                ->whereIn('statut', [StatutFacture::Validee, StatutFacture::Annulee])
                ->lockForUpdate()
                ->get();

            // Chronological constraint
            $lastValidated = $exerciceFactures->sortByDesc('date')->first();
            if ($lastValidated && $factureVerrouillee->date->lt($lastValidated->date)) {
                throw new \RuntimeException(
                    "La date doit être postérieure ou égale au {$lastValidated->date->format('d/m/Y')} (dernière facture validée {$lastValidated->numero})."
                );
            }

            // Sequential numbering (from locked set)
            $maxSeq = $exerciceFactures
                ->filter(fn ($f) => $f->numero !== null)
                ->map(fn ($f) => (int) last(explode('-', $f->numero)))
                ->max() ?? 0;

            $seq = $maxSeq + 1;
            $numero = sprintf('F-%d-%04d', $factureVerrouillee->exercice, $seq);

            $montantTotal = (float) $factureVerrouillee->lignes()
                ->whereIn('type', [TypeLigneFacture::Montant->value, TypeLigneFacture::MontantManuel->value])
                ->sum('montant');

            $factureVerrouillee->update([
                'numero' => $numero,
                'montant_total' => $montantTotal,
                'statut' => StatutFacture::Validee,
            ]);

            // Sync local instance for libellé generation
            $facture->numero = $numero;
            $facture->statut = StatutFacture::Validee;

            // Génère Transaction + TransactionLignes pour les lignes MontantManuel
            return $this->genererTransactionDepuisLignesManuelles($factureVerrouillee);
        });

        // Émet le log après le commit (hors transaction pour éviter le rollback du log)
        Log::info('facture.valide', [
            'facture_id' => (int) $facture->id,
            'transaction_id_generee' => $transactionGeneree !== null ? (int) $transactionGeneree->id : null,
        ]);

        // Refresh local instance to expose numero/statut attribués
        $facture->refresh();
    }

    /**
     * Swap the ordre of a facture ligne with its neighbour (up or down).
     */
    public function majOrdre(Facture $facture, int $ligneId, string $direction): void
    {
        $this->assertBrouillon($facture);

        $lignes = $facture->lignes()->orderBy('ordre')->get();
        $index = $lignes->search(fn ($l) => $l->id === $ligneId);

        if ($index === false) {
            return;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapIndex < 0 || $swapIndex >= $lignes->count()) {
            return;
        }

        $ordreA = $lignes[$index]->ordre;
        $ordreB = $lignes[$swapIndex]->ordre;
        $lignes[$index]->update(['ordre' => $ordreB]);
        $lignes[$swapIndex]->update(['ordre' => $ordreA]);
    }

    /**
     * Update the libellé of a facture ligne.
     */
    public function majLibelle(Facture $facture, int $ligneId, string $libelle): void
    {
        $this->assertBrouillon($facture);
        $facture->lignes()->where('id', $ligneId)->update(['libelle' => $libelle]);
    }

    /**
     * Add a text-only line (no montant) to the facture.
     */
    public function ajouterLigneTexte(Facture $facture, string $texte): void
    {
        $this->assertBrouillon($facture);

        $maxOrdre = (int) $facture->lignes()->max('ordre');

        $facture->lignes()->create([
            'type' => TypeLigneFacture::Texte,
            'libelle' => $texte,
            'montant' => null,
            'transaction_ligne_id' => null,
            'ordre' => $maxOrdre + 1,
        ]);
    }

    /**
     * Delete a texte or MontantManuel line from the facture.
     * Lignes de type Montant (liées à une transaction) ne peuvent pas être supprimées ici.
     * La suppression d'une ligne MontantManuel recalcule montant_total.
     */
    public function supprimerLigne(Facture $facture, int $ligneId): void
    {
        $this->assertBrouillon($facture);

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type === TypeLigneFacture::Montant) {
            throw new \RuntimeException('Les lignes liées à une transaction ne peuvent pas être supprimées individuellement — utilisez « Retirer la transaction ».');
        }

        $ligne->delete();

        if ($ligne->type === TypeLigneFacture::MontantManuel) {
            $this->recalculerMontantTotal($facture);
        }
    }

    /**
     * Mise à jour de la sous-catégorie d'une ligne manuelle (MontantManuel).
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si la ligne n'est pas de type MontantManuel
     */
    public function majSousCategorieLigne(Facture $facture, int $ligneId, ?int $sousCategorieId): void
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::MontantManuel) {
            throw new \RuntimeException('La sous-catégorie ne peut être modifiée que sur une ligne manuelle.');
        }

        $ligne->update(['sous_categorie_id' => $sousCategorieId]);
    }

    /**
     * Mise à jour de l'opération d'une ligne manuelle (MontantManuel).
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si la ligne n'est pas de type MontantManuel
     */
    public function majOperationLigne(Facture $facture, int $ligneId, ?int $operationId): void
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::MontantManuel) {
            throw new \RuntimeException("L'opération ne peut être modifiée que sur une ligne manuelle.");
        }

        // Changer d'opération invalide la séance (qui réfère à une plage 1..nombre_seances spécifique).
        $ligne->update(['operation_id' => $operationId, 'seance' => null]);
    }

    /**
     * Mise à jour du numéro de séance d'une ligne manuelle (MontantManuel).
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si la ligne n'est pas de type MontantManuel
     */
    public function majSeanceLigne(Facture $facture, int $ligneId, ?int $seance): void
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::MontantManuel) {
            throw new \RuntimeException('Le numéro de séance ne peut être modifié que sur une ligne manuelle.');
        }

        $ligne->update(['seance' => $seance]);
    }

    /**
     * Cancel a validated invoice by issuing a credit note (avoir).
     *
     * For each MontantManuel transaction generated by this invoice: extourne d'office
     * via the S1 primitive (TransactionExtourneService::extourner), which handles
     * automatic lettrage if the origin was EnAttente.
     *
     * The facture statut is flipped to Annulee BEFORE calling the primitive so that
     * the S1 guard "Cette transaction est portée par la facture F-XXXX validée" does
     * not reject the extourne.
     *
     * The guard isLockedByRapprochement() has been removed: S1 handles that case by
     * creating an EnAttente extourne to be pointed later (no automatic lettrage).
     */
    public function annuler(Facture $facture): void
    {
        $this->assertTenantOwnership($facture);

        if ($facture->statut !== StatutFacture::Validee) {
            throw new \RuntimeException('Seule une facture validée peut être annulée.');
        }

        Gate::authorize('annuler', $facture);

        $exerciceCourant = $this->exerciceService->current();

        DB::transaction(function () use ($facture, $exerciceCourant): void {
            // Lock avoirs of current exercise for sequential numbering
            $numeroAvoir = $this->calculerNumeroAvoir($exerciceCourant);

            // Flip statut to Annulee BEFORE calling the S1 primitive.
            // The primitive asserts the transaction is not carried by a Validee facture
            // — once we flip to Annulee that guard no longer fires.
            $facture->update([
                'statut' => StatutFacture::Annulee,
                'numero_avoir' => $numeroAvoir,
                'date_annulation' => now()->toDateString(),
            ]);

            // Extourne d'office all MontantManuel transactions generated by this invoice.
            // The S1 primitive handles lettrage automatique (if EnAttente) or creates an
            // EnAttente miroir for later bank pointage (if Pointe/Recu).
            // The pivot facture_transaction is NOT detached for MontantManuel (AC-17).
            foreach ($facture->transactionsGenereesParLignesManuelles() as $tg) {
                $this->extourneService->extourner(
                    $tg,
                    ExtournePayload::fromOrigine($tg),
                );
            }

            // Détacher du pivot les TX référencées (Montant ref, sans extourne).
            // These transactions pre-existed the invoice; they are simply unlinked so
            // they can be re-attached to a new brouillon facture (AC-5, AC-18).
            foreach ($facture->transactionsReferencees() as $tref) {
                $facture->transactions()->detach($tref->id);
            }
        });
    }

    /**
     * Calcule le prochain numero_avoir séquentiel pour l'exercice donné.
     *
     * Utilise lockForUpdate sur les avoirs existants pour garantir l'unicité
     * en cas d'annulations concurrentes.
     */
    private function calculerNumeroAvoir(int $exerciceCourant): string
    {
        $existingAvoirs = Facture::where('exercice', $exerciceCourant)
            ->where('statut', StatutFacture::Annulee)
            ->whereNotNull('numero_avoir')
            ->lockForUpdate()
            ->get();

        $maxSeq = $existingAvoirs
            ->map(fn ($f) => (int) last(explode('-', (string) $f->numero_avoir)))
            ->max() ?? 0;

        $seq = $maxSeq + 1;

        return sprintf('AV-%d-%04d', $exerciceCourant, $seq);
    }

    /**
     * Generate a Factur-X compliant PDF (PDF/A-3) for the given facture.
     */
    public function genererPdf(Facture $facture, bool $forceOriginalFormat = false): string
    {
        $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions']);
        $association = CurrentAssociation::get();

        // Step 1: generate visual PDF via dompdf
        $headerLogoBase64 = null;
        $headerLogoMime = null;
        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $logoContent = Storage::disk('local')->get($logoFullPath);
            if ($logoContent) {
                $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
                $headerLogoMime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
                $headerLogoBase64 = base64_encode($logoContent);
            }
        }

        $pdf = Pdf::loadView('pdf.facture', [
            'facture' => $facture,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'montantRegle' => $facture->montantRegle(),
            'isAcquittee' => $facture->isAcquittee(),
            'mentionsPenalites' => $association?->facture_mentions_penalites,
            'forceOriginalFormat' => $forceOriginalFormat,
        ])->setPaper('a4', 'portrait');

        $pdfContent = $pdf->output();

        // Brouillon, annulée, ou reprint original : plain PDF (pas de Factur-X)
        if ($facture->statut !== StatutFacture::Validee || $forceOriginalFormat) {
            return $pdfContent;
        }

        // Step 2: generate Factur-X XML (profile MINIMUM)
        $xml = $this->genererFacturXml($facture, $association);

        // Step 3: embed XML into PDF via atgp/factur-x -> PDF/A-3
        $writer = new FacturXWriter;

        return $writer->generate($pdfContent, $xml, 'minimum', false);
    }

    /**
     * Generate Factur-X MINIMUM profile XML for the given facture.
     */
    private function genererFacturXml(Facture $facture, ?Association $association): string
    {
        $numero = $facture->numero ?? 'BROUILLON';
        $dateFormatted = $facture->date->format('Ymd');
        $sellerName = htmlspecialchars($association?->nom ?? '', ENT_XML1, 'UTF-8');
        $siret = htmlspecialchars($association?->siret ?? '', ENT_XML1, 'UTF-8');
        $buyerName = htmlspecialchars($facture->tiers->displayName(), ENT_XML1, 'UTF-8');
        $montantTotal = number_format((float) $facture->montant_total, 2, '.', '');
        $duePayable = number_format((float) $facture->montant_total - $facture->montantRegle(), 2, '.', '');

        $siretBlock = '';
        if ($siret !== '') {
            $siretBlock = <<<XML
                <ram:SpecifiedLegalOrganization>
                    <ram:ID schemeID="0002">{$siret}</ram:ID>
                </ram:SpecifiedLegalOrganization>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>urn:factur-x.eu:1p0:minimum</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>
    <rsm:ExchangedDocument>
        <ram:ID>{$numero}</ram:ID>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">{$dateFormatted}</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>{$sellerName}</ram:Name>
                {$siretBlock}
            </ram:SellerTradeParty>
            <ram:BuyerTradeParty>
                <ram:Name>{$buyerName}</ram:Name>
            </ram:BuyerTradeParty>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeDelivery/>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:TaxBasisTotalAmount>{$montantTotal}</ram:TaxBasisTotalAmount>
                <ram:GrandTotalAmount>{$montantTotal}</ram:GrandTotalAmount>
                <ram:DuePayableAmount>{$duePayable}</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }

    /**
     * Mark selected transactions as "payment received" (statut_reglement = recu).
     * Does not move transactions — used for chèques/espèces awaiting deposit.
     *
     * @param  array<int>  $transactionIds
     */
    public function marquerReglementRecu(
        Facture $facture,
        array $transactionIds,
    ): void {
        if ($facture->statut !== StatutFacture::Validee) {
            throw new \RuntimeException('Seule une facture validée peut être encaissée.');
        }

        if ($facture->isAcquittee()) {
            throw new \RuntimeException('Cette facture est déjà intégralement réglée.');
        }

        DB::transaction(function () use ($facture, $transactionIds): void {
            foreach ($transactionIds as $transactionId) {
                $transaction = $facture->transactions()->findOrFail($transactionId);

                $transaction->update([
                    'statut_reglement' => StatutReglement::Recu->value,
                ]);
            }
        });
    }

    /**
     * Ajoute une ligne manuelle de type MontantManuel à une facture brouillon.
     *
     * $attrs accepte : libelle (requis), prix_unitaire (requis, > 0), quantite (requis, > 0),
     * sous_categorie_id (optionnel), operation_id (optionnel), seance (optionnel).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si prix_unitaire / quantite ne sont pas strictement positifs
     */
    public function ajouterLigneManuelle(Facture $facture, array $attrs): FactureLigne
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        $prixUnitaire = (float) ($attrs['prix_unitaire'] ?? 0);
        $quantite = (float) ($attrs['quantite'] ?? 0);

        if ($prixUnitaire <= 0 || $quantite <= 0) {
            throw new \RuntimeException(
                'Le prix unitaire et la quantité doivent être strictement positifs (les montants négatifs ne sont pas supportés).'
            );
        }

        return DB::transaction(function () use ($facture, $attrs, $prixUnitaire, $quantite): FactureLigne {
            $maxOrdre = (int) FactureLigne::where('facture_id', $facture->id)->max('ordre');

            $montant = round($prixUnitaire * $quantite, 2);

            $ligne = FactureLigne::create([
                'facture_id' => $facture->id,
                'type' => TypeLigneFacture::MontantManuel,
                'libelle' => $attrs['libelle'],
                'prix_unitaire' => $prixUnitaire,
                'quantite' => $quantite,
                'montant' => $montant,
                'transaction_ligne_id' => null,
                'sous_categorie_id' => $attrs['sous_categorie_id'] ?? null,
                'operation_id' => $attrs['operation_id'] ?? null,
                'seance' => $attrs['seance'] ?? null,
                'ordre' => $maxOrdre + 1,
            ]);

            $this->recalculerMontantTotal($facture);

            return $ligne;
        });
    }

    /**
     * Ajoute une ligne d'information de type Texte à une facture brouillon (manuelle).
     *
     * La ligne n'a aucun impact comptable (montant = null). Le total facture est inchangé.
     *
     * @throws \RuntimeException si la facture n'est pas brouillon ou si le tenant ne correspond pas
     */
    public function ajouterLigneTexteManuelle(Facture $facture, string $libelle): FactureLigne
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        return DB::transaction(function () use ($facture, $libelle): FactureLigne {
            $maxOrdre = (int) FactureLigne::where('facture_id', $facture->id)->max('ordre');

            $ligne = FactureLigne::create([
                'facture_id' => $facture->id,
                'type' => TypeLigneFacture::Texte,
                'libelle' => $libelle,
                'prix_unitaire' => null,
                'quantite' => null,
                'montant' => null,
                'transaction_ligne_id' => null,
                'sous_categorie_id' => null,
                'operation_id' => null,
                'seance' => null,
                'ordre' => $maxOrdre + 1,
            ]);

            $this->recalculerMontantTotal($facture);

            return $ligne;
        });
    }

    /**
     * Mise à jour du prix unitaire d'une ligne manuelle (MontantManuel).
     *
     * Recalcule montant = prix_unitaire × quantite et met à jour montant_total.
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si la ligne n'est pas de type MontantManuel, ou si prix_unitaire ≤ 0
     */
    public function majPrixUnitaireLigneManuelle(Facture $facture, int $ligneId, float $prixUnitaire): void
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        if ($prixUnitaire <= 0) {
            throw new \RuntimeException('Le prix unitaire doit être strictement positif (les montants négatifs ne sont pas supportés).');
        }

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::MontantManuel) {
            throw new \RuntimeException('Le prix unitaire ne peut être modifié que sur une ligne manuelle.');
        }

        $montant = round($prixUnitaire * (float) $ligne->quantite, 2);
        $ligne->update(['prix_unitaire' => $prixUnitaire, 'montant' => $montant]);

        $this->recalculerMontantTotal($facture);
    }

    /**
     * Mise à jour de la quantité d'une ligne manuelle (MontantManuel).
     *
     * Recalcule montant = prix_unitaire × quantite et met à jour montant_total.
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si la ligne n'est pas de type MontantManuel, ou si quantite ≤ 0
     */
    public function majQuantiteLigneManuelle(Facture $facture, int $ligneId, float $quantite): void
    {
        $this->assertBrouillon($facture);
        $this->assertTenantOwnership($facture);

        if ($quantite <= 0) {
            throw new \RuntimeException('La quantité doit être strictement positive (les montants négatifs ne sont pas supportés).');
        }

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::MontantManuel) {
            throw new \RuntimeException('La quantité ne peut être modifiée que sur une ligne manuelle.');
        }

        $montant = round((float) $ligne->prix_unitaire * $quantite, 2);
        $ligne->update(['quantite' => $quantite, 'montant' => $montant]);

        $this->recalculerMontantTotal($facture);
    }

    /**
     * Recalcule et persiste montant_total sur la facture : somme des montants non-null de toutes les lignes.
     */
    private function recalculerMontantTotal(Facture $facture): void
    {
        $total = (float) FactureLigne::where('facture_id', $facture->id)
            ->whereNotNull('montant')
            ->sum('montant');

        $facture->update(['montant_total' => $total]);
    }

    /**
     * Guards spécifiques aux factures portant ≥ 1 ligne MontantManuel.
     *
     * Exécutés AVANT toute mutation et AVANT l'attribution du numéro.
     *
     * 1. mode_paiement_prevu doit être non-null.
     * 2. Chaque ligne MontantManuel doit avoir sous_categorie_id non-null.
     *
     * Si la facture ne porte aucune ligne MontantManuel, cette méthode est no-op
     * (les factures classiques ne sont pas impactées).
     *
     * @throws \RuntimeException si un guard échoue
     */
    private function assertGuardsLignesManuelles(Facture $facture): void
    {
        $lignesManuelles = $facture->lignes()
            ->where('type', TypeLigneFacture::MontantManuel->value)
            ->get();

        if ($lignesManuelles->isEmpty()) {
            return;
        }

        if ($facture->mode_paiement_prevu === null) {
            throw new \RuntimeException(
                'Le mode de règlement prévisionnel est requis pour valider une facture portant des lignes manuelles.'
            );
        }

        $lignesSansSousCat = $lignesManuelles->filter(
            fn (FactureLigne $l) => $l->sous_categorie_id === null
        );

        if ($lignesSansSousCat->isNotEmpty()) {
            throw new \RuntimeException(
                'La sous-catégorie est requise sur chaque ligne montant pour valider la facture.'
            );
        }
    }

    /**
     * Génère 1 Transaction recette + N TransactionLignes pour les lignes MontantManuel de la facture.
     *
     * Doit être appelée APRÈS attribution du numéro, à l'intérieur d'une DB::transaction.
     * Retourne null si la facture ne porte aucune ligne MontantManuel.
     */
    private function genererTransactionDepuisLignesManuelles(Facture $facture): ?Transaction
    {
        $lignesManuelles = $facture->lignes()
            ->where('type', TypeLigneFacture::MontantManuel->value)
            ->get();

        if ($lignesManuelles->isEmpty()) {
            return null;
        }

        $montantTotal = $lignesManuelles->sum(fn (FactureLigne $l) => (float) $l->montant);

        // 1. Crée la Transaction recette entête
        $transaction = Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => TypeTransaction::Recette,
            'tiers_id' => (int) $facture->tiers_id,
            'compte_id' => $facture->compte_bancaire_id !== null ? (int) $facture->compte_bancaire_id : null,
            'date' => now()->toDateString(),
            'libelle' => "Facture {$facture->numero}",
            'montant_total' => round($montantTotal, 2),
            'mode_paiement' => $facture->mode_paiement_prevu?->value,
            'statut_reglement' => StatutReglement::EnAttente->value,
            'saisi_par' => auth()->id(),
        ]);

        // 2. Crée les TransactionLignes + 3. set facture_lignes.transaction_ligne_id
        foreach ($lignesManuelles as $factureLigne) {
            $transactionLigne = TransactionLigne::create([
                'transaction_id' => $transaction->id,
                'sous_categorie_id' => $factureLigne->sous_categorie_id,
                'operation_id' => $factureLigne->operation_id,
                'seance' => $factureLigne->seance,
                'montant' => $factureLigne->montant,
                'notes' => $factureLigne->libelle,
            ]);

            // 3. Lie la FactureLigne à sa TransactionLigne
            $factureLigne->update(['transaction_ligne_id' => $transactionLigne->id]);
        }

        // 4. Attache la Transaction au pivot facture_transaction
        $facture->transactions()->attach($transaction->id);

        return $transaction;
    }

    /**
     * Guard multi-tenant : vérifie que la facture appartient à l'association courante.
     *
     * @throws \RuntimeException si la facture appartient à une autre association
     */
    private function assertTenantOwnership(Facture $facture): void
    {
        if ((int) $facture->association_id !== (int) TenantContext::currentId()) {
            throw new \RuntimeException("Accès interdit : cette facture n'appartient pas à votre association.");
        }
    }

    private function assertBrouillon(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            throw new \RuntimeException('Cette action n\'est possible que sur un brouillon.');
        }
    }

    /**
     * Generate auto-libellé for a facture ligne based on the transaction ligne.
     */
    private function genererLibelleLigne(TransactionLigne $ligne): string
    {
        $sousCategorie = $ligne->sousCategorie?->nom ?? '';
        $operation = $ligne->operation?->nom;

        if ($operation === null) {
            return $sousCategorie;
        }

        $parts = [$sousCategorie, $operation];

        if ($ligne->seance !== null) {
            $seanceLabel = "Séance {$ligne->seance}";

            // Try to find the séance date
            $seanceDate = Seance::where('operation_id', $ligne->operation_id)
                ->where('numero', $ligne->seance)
                ->value('date');

            if ($seanceDate !== null) {
                $seanceLabel .= ' du '.Carbon::parse($seanceDate)->format('d/m/Y');
            }

            $parts[] = $seanceLabel;
        }

        return implode(' — ', $parts);
    }
}
