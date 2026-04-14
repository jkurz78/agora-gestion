<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Seance;
use App\Models\Transaction;
use App\Models\TransactionLigne;
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
            $association = Association::first();

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

        $montantLignes = $facture->lignes()
            ->where('type', TypeLigneFacture::Montant)
            ->count();
        if ($montantLignes === 0) {
            throw new \RuntimeException('La facture doit contenir au moins une ligne avec montant.');
        }

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
                ->where('type', TypeLigneFacture::Montant)
                ->sum('montant');

            $facture->update([
                'numero' => $numero,
                'montant_total' => $montantTotal,
                'statut' => StatutFacture::Validee,
            ]);
        });
    }

    /**
     * Move selected transactions from a system account (Créances à recevoir)
     * to a real bank account, marking them as paid on the invoice.
     *
     * @param  array<int>  $transactionIds
     */
    public function encaisser(Facture $facture, array $transactionIds, int $compteBancaireId): void
    {
        if ($facture->statut !== StatutFacture::Validee) {
            throw new \RuntimeException('Seule une facture validée peut être encaissée.');
        }

        if ($facture->isAcquittee()) {
            throw new \RuntimeException('Cette facture est déjà intégralement réglée.');
        }

        $compteDestination = CompteBancaire::findOrFail($compteBancaireId);
        if ($compteDestination->est_systeme) {
            throw new \RuntimeException('Le compte de destination doit être un compte bancaire réel.');
        }

        DB::transaction(function () use ($facture, $transactionIds, $compteBancaireId): void {
            foreach ($transactionIds as $transactionId) {
                $transaction = $facture->transactions()->findOrFail($transactionId);

                if (! $transaction->compte->est_systeme) {
                    throw new \RuntimeException('Cette transaction est déjà encaissée.');
                }

                $transaction->update([
                    'compte_id' => $compteBancaireId,
                    'statut_reglement' => \App\Enums\StatutReglement::Recu->value,
                ]);
            }
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
        $association = Association::first();

        // Step 1: generate visual PDF via dompdf
        $headerLogoBase64 = null;
        $headerLogoMime = null;
        if ($association?->logo_path) {
            $logoContent = Storage::disk('public')->get($association->logo_path);
            if ($logoContent) {
                $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
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
                    'statut_reglement' => \App\Enums\StatutReglement::Recu->value,
                ]);
            }
        });
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
