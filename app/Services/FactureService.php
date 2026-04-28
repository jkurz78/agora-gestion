<?php

declare(strict_types=1);

namespace App\Services;

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
use Illuminate\Support\Facades\Storage;

final class FactureService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
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
     * Crée une facture brouillon vierge sans devis source (facture libre directe).
     *
     * Aucune ligne n'est créée. Le numéro reste null (statut brouillon).
     * Le tiers doit appartenir à l'association courante (guard multi-tenant).
     *
     * @throws \RuntimeException si le tiers n'appartient pas à l'association courante
     *                           ou si TenantContext n'est pas booté
     */
    public function creerLibreVierge(int $tiersId): Facture
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
     */
    public function valider(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            throw new \RuntimeException('Seul un brouillon peut être validé.');
        }

        // Compte les lignes ayant un impact comptable (Montant ref OU MontantLibre)
        $lignesAvecMontant = $facture->lignes()
            ->whereIn('type', [TypeLigneFacture::Montant->value, TypeLigneFacture::MontantLibre->value])
            ->count();
        if ($lignesAvecMontant === 0) {
            throw new \RuntimeException('La facture doit contenir au moins une ligne avec montant.');
        }

        // Guards spécifiques aux factures portant ≥ 1 ligne MontantLibre
        $this->assertGuardsLignesLibres($facture);

        DB::transaction(function () use ($facture) {
            // Check exercice is open inside the transaction
            $this->exerciceService->assertOuvert($facture->exercice);

            // Lock ALL factures of this exercice upfront (single lock for both
            // chronological constraint and sequential numbering)
            $exerciceFactures = Facture::where('exercice', $facture->exercice)
                ->whereIn('statut', [StatutFacture::Validee, StatutFacture::Annulee])
                ->lockForUpdate()
                ->get();

            // Chronological constraint
            $lastValidated = $exerciceFactures->sortByDesc('date')->first();
            if ($lastValidated && $facture->date->lt($lastValidated->date)) {
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
            $numero = sprintf('F-%d-%04d', $facture->exercice, $seq);

            $montantTotal = (float) $facture->lignes()
                ->whereIn('type', [TypeLigneFacture::Montant->value, TypeLigneFacture::MontantLibre->value])
                ->sum('montant');

            $facture->update([
                'numero' => $numero,
                'montant_total' => $montantTotal,
                'statut' => StatutFacture::Validee,
            ]);
        });
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
     * Delete a text line from the facture. Only texte lines can be individually deleted.
     */
    public function supprimerLigne(Facture $facture, int $ligneId): void
    {
        $this->assertBrouillon($facture);

        $ligne = $facture->lignes()->findOrFail($ligneId);

        if ($ligne->type !== TypeLigneFacture::Texte) {
            throw new \RuntimeException('Seules les lignes de texte peuvent être supprimées individuellement.');
        }

        $ligne->delete();
    }

    /**
     * Cancel a validated invoice by issuing a credit note (avoir).
     */
    public function annuler(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Validee) {
            throw new \RuntimeException('Seule une facture validée peut être annulée.');
        }

        // Check no linked transaction is locked by rapprochement
        foreach ($facture->transactions as $tx) {
            if ($tx->isLockedByRapprochement()) {
                throw new \RuntimeException(
                    "La transaction « {$tx->libelle} » est rapprochée en banque. Veuillez d'abord annuler le rapprochement."
                );
            }
        }

        $exerciceCourant = $this->exerciceService->current();

        DB::transaction(function () use ($facture, $exerciceCourant): void {
            // Lock avoirs of current exercise for sequential numbering
            $existingAvoirs = Facture::where('exercice', $exerciceCourant)
                ->where('statut', StatutFacture::Annulee)
                ->whereNotNull('numero_avoir')
                ->lockForUpdate()
                ->get();

            $maxSeq = $existingAvoirs
                ->map(fn ($f) => (int) last(explode('-', $f->numero_avoir)))
                ->max() ?? 0;

            $seq = $maxSeq + 1;
            $numeroAvoir = sprintf('AV-%d-%04d', $exerciceCourant, $seq);

            $facture->update([
                'statut' => StatutFacture::Annulee,
                'numero_avoir' => $numeroAvoir,
                'date_annulation' => now()->toDateString(),
            ]);
        });
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
     * Ajoute une ligne libre de type MontantLibre à une facture brouillon.
     *
     * $attrs accepte : libelle (requis), prix_unitaire (requis, > 0), quantite (requis, > 0),
     * sous_categorie_id (optionnel), operation_id (optionnel), seance (optionnel).
     *
     * @param  array<string, mixed>  $attrs
     *
     * @throws \RuntimeException si la facture n'est pas brouillon, si le tenant ne correspond pas,
     *                           ou si prix_unitaire / quantite ne sont pas strictement positifs
     */
    public function ajouterLigneLibreMontant(Facture $facture, array $attrs): FactureLigne
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
                'type' => TypeLigneFacture::MontantLibre,
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
     * Ajoute une ligne d'information de type Texte à une facture brouillon.
     *
     * La ligne n'a aucun impact comptable (montant = null). Le total facture est inchangé.
     *
     * @throws \RuntimeException si la facture n'est pas brouillon ou si le tenant ne correspond pas
     */
    public function ajouterLigneLibreTexte(Facture $facture, string $libelle): FactureLigne
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
     * Guards spécifiques aux factures portant ≥ 1 ligne MontantLibre.
     *
     * Exécutés AVANT toute mutation et AVANT l'attribution du numéro.
     *
     * 1. mode_paiement_prevu doit être non-null.
     * 2. Chaque ligne MontantLibre doit avoir sous_categorie_id non-null.
     *
     * Si la facture ne porte aucune ligne MontantLibre, cette méthode est no-op
     * (les factures classiques ne sont pas impactées).
     *
     * @throws \RuntimeException si un guard échoue
     */
    private function assertGuardsLignesLibres(Facture $facture): void
    {
        $lignesLibres = $facture->lignes()
            ->where('type', TypeLigneFacture::MontantLibre->value)
            ->get();

        if ($lignesLibres->isEmpty()) {
            return;
        }

        if ($facture->mode_paiement_prevu === null) {
            throw new \RuntimeException(
                'Le mode de règlement prévisionnel est requis pour valider une facture portant des lignes libres.'
            );
        }

        $lignesansSousCat = $lignesLibres->filter(
            fn (FactureLigne $l) => $l->sous_categorie_id === null
        );

        if ($lignesansSousCat->isNotEmpty()) {
            throw new \RuntimeException(
                'La sous-catégorie est requise sur chaque ligne montant pour valider la facture.'
            );
        }
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
