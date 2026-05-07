<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Atgp\FacturX\Writer as FacturXWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RecuFiscalService
{
    public function validerEligibilite(TransactionLigne $ligne): void
    {
        $asso = Association::findOrFail(TenantContext::currentId());

        if (! $asso->eligible_recu_fiscal) {
            throw RecuFiscalException::associationNonEligible();
        }

        if (empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            throw RecuFiscalException::signataireManquant();
        }

        if (! $ligne->sousCategorie) {
            throw RecuFiscalException::sansSousCategorie();
        }

        $transaction = $ligne->transaction;

        if (! $transaction->statut_reglement->isEncaisse()) {
            throw RecuFiscalException::transactionNonEncaissee();
        }

        $tiers = $transaction->tiers;
        $champsObligatoires = [
            'adresse_ligne1' => 'rue',
            'code_postal' => 'code postal',
            'ville' => 'ville',
        ];

        foreach ($champsObligatoires as $champ => $libelle) {
            if (empty($tiers->{$champ})) {
                throw RecuFiscalException::adresseDonateurManquante($libelle);
            }
        }
    }

    public function obtenirOuGenerer(TransactionLigne $ligne, ?User $user = null): RecuFiscalEmis
    {
        return DB::transaction(function () use ($ligne, $user) {
            $existant = RecuFiscalEmis::query()
                ->where('transaction_ligne_id', $ligne->id)
                ->whereNull('annule_at')
                ->lockForUpdate()
                ->first();

            if ($existant !== null) {
                return $existant;
            }

            $this->validerEligibilite($ligne);

            $asso = Association::findOrFail(TenantContext::currentId());
            $tiers = $ligne->transaction->tiers;
            $sousCat = $ligne->sousCategorie;
            $dateVersement = $ligne->transaction->date;
            $anneeCivile = (int) $dateVersement->format('Y');

            $articleCgi = $this->determinerArticleCgi($tiers);
            $formeDon = $this->determinerFormeDon($sousCat);
            $modeVersement = $ligne->transaction->mode_paiement?->value ?? 'autre';
            $numero = $this->allouerNumero($anneeCivile);

            $pdfBinaire = $this->genererPdfBinaire($asso, $tiers, $ligne, $numero, $articleCgi, $formeDon, $modeVersement);
            $relativePath = "recus_fiscaux/{$anneeCivile}/{$numero}.pdf";
            $fullPath = "associations/{$asso->id}/{$relativePath}";
            Storage::disk('local')->put($fullPath, $pdfBinaire);

            $hash = hash('sha256', $pdfBinaire);

            return RecuFiscalEmis::create([
                'association_id' => $asso->id,
                'numero' => $numero,
                'annee_civile' => $anneeCivile,
                'tiers_id' => $tiers->id,
                'transaction_ligne_id' => $ligne->id,
                'montant_centimes' => (int) round((float) $ligne->montant * 100),
                'date_versement' => $dateVersement,
                'mode_versement' => $modeVersement,
                'forme_don' => $formeDon,
                'article_cgi' => $articleCgi,
                'pdf_path' => $relativePath,
                'pdf_hash' => $hash,
                'emitted_at' => now(),
                'emitted_by_user_id' => $user?->id,
            ]);
        });
    }

    public function streamPdf(RecuFiscalEmis $recu): StreamedResponse
    {
        if (! $recu->verifierIntegrite()) {
            throw new \RuntimeException("Intégrité du PDF reçu n°{$recu->numero} compromise — hash incorrect");
        }

        $filename = "recu-fiscal-{$recu->numero}.pdf";

        return Storage::disk('local')->download($recu->pdfFullPath(), $filename);
    }

    private function allouerNumero(int $annee): string
    {
        return DB::transaction(function () use ($annee) {
            $associationId = TenantContext::currentId();

            $dernier = RecuFiscalEmis::query()
                ->where('association_id', $associationId)
                ->where('annee_civile', $annee)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $sequence = 1;
            if ($dernier !== null) {
                $parts = explode('-', $dernier->numero);
                $sequence = (int) end($parts) + 1;
            }

            return sprintf('%d-%04d', $annee, $sequence);
        });
    }

    private function determinerArticleCgi(Tiers $donateur): string
    {
        return $donateur->type === 'entreprise' ? 'art_238_bis' : 'art_200';
    }

    private function determinerFormeDon(SousCategorie $sc): string
    {
        return $sc->hasUsage(UsageComptable::AbandonCreance)
            ? 'abandon_revenus'
            : 'numeraire';
    }

    private function genererPdfBinaire(
        Association $asso,
        Tiers $donateur,
        TransactionLigne $ligne,
        string $numero,
        string $articleCgi,
        string $formeDon,
        string $modeVersement,
    ): string {
        $montantFloat = (float) $ligne->montant;
        $montantFormate = number_format($montantFloat, 2, ',', ' ').' €';
        $montantEnLettres = app(MontantEnLettresService::class)->convertir($montantFloat);

        $articleCgiLibelle = match ($articleCgi) {
            'art_200' => 'article 200',
            'art_238_bis' => 'article 238 bis',
            default => $articleCgi,
        };

        $formeLibelle = match ($formeDon) {
            'numeraire' => 'Don manuel en numéraire',
            'abandon_revenus' => "Le donateur renonce expressément au remboursement des frais engagés dans le cadre de son activité bénévole et entend en faire don à l'association.",
            default => $formeDon,
        };

        $modeLibelle = match ($modeVersement) {
            'cheque' => 'Chèque',
            'virement' => 'Virement bancaire',
            'espece', 'especes' => 'Espèces',
            'carte', 'carte_bancaire' => 'Carte bancaire',
            default => 'Autre',
        };

        // Temporary object that mimics a persisted RecuFiscalEmis for the Blade view
        $recuTemporaire = new RecuFiscalEmis([
            'numero' => $numero,
            'emitted_at' => now(),
            'date_versement' => $ligne->transaction->date,
            'montant_centimes' => (int) round($montantFloat * 100),
            'mode_versement' => $modeVersement,
            'forme_don' => $formeDon,
            'article_cgi' => $articleCgi,
            'annule_at' => null,
        ]);

        // Load logo base64 — same pattern as DocumentPrevisionnelService::genererPdf
        $headerLogoBase64 = null;
        $headerLogoMime = null;
        $logoFullPath = $asso->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $logoContent = Storage::disk('local')->get($logoFullPath);
            if ($logoContent) {
                $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
                $headerLogoMime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
                $headerLogoBase64 = base64_encode($logoContent);
            }
        }

        // Load cachet/signature base64
        $cachetBase64 = null;
        $cachetMime = null;
        $cachetFullPath = $asso->brandingCachetFullPath();
        if ($cachetFullPath && Storage::disk('local')->exists($cachetFullPath)) {
            $cachetContent = Storage::disk('local')->get($cachetFullPath);
            if ($cachetContent) {
                $ext = strtolower(pathinfo($cachetFullPath, PATHINFO_EXTENSION));
                $cachetMime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
                $cachetBase64 = base64_encode($cachetContent);
            }
        }

        // App logo (AgoraGestion SVG) for PDF footer — same pattern as FactureService::genererPdf
        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode((string) file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.recu-fiscal-don', [
            'recu' => $recuTemporaire,
            'asso' => $asso,
            'donateur' => $donateur,
            'montantFormate' => $montantFormate,
            'montantEnLettres' => $montantEnLettres,
            'articleCgiLibelle' => $articleCgiLibelle,
            'formeLibelle' => $formeLibelle,
            'modeLibelle' => $modeLibelle,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
        ])->setPaper('a4', 'portrait');

        $pdfContent = $pdf->output();
        $xml = $this->genererMetadataXml($numero, $ligne, $asso, $donateur);
        $writer = new FacturXWriter;

        return $writer->generate($pdfContent, $xml, 'minimum', false);
    }

    private function genererMetadataXml(string $numero, TransactionLigne $ligne, Association $asso, Tiers $donateur): string
    {
        $date = now()->format('Ymd');
        $montant = number_format((float) $ligne->montant, 2, '.', '');
        $sellerName = htmlspecialchars($asso->nom ?? '', ENT_XML1, 'UTF-8');
        $siret = htmlspecialchars($asso->siret ?? '', ENT_XML1, 'UTF-8');
        $buyerName = htmlspecialchars($donateur->displayName(), ENT_XML1, 'UTF-8');
        $numeroXml = htmlspecialchars($numero, ENT_XML1, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocument>
    <ram:ID>{$numeroXml}</ram:ID>
    <ram:TypeCode>325</ram:TypeCode>
    <ram:IssueDateTime><udt:DateTimeString format="102">{$date}</udt:DateTimeString></ram:IssueDateTime>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty><ram:Name>{$sellerName}</ram:Name><ram:SpecifiedLegalOrganization><ram:ID>{$siret}</ram:ID></ram:SpecifiedLegalOrganization></ram:SellerTradeParty>
      <ram:BuyerTradeParty><ram:Name>{$buyerName}</ram:Name></ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:GrandTotalAmount currencyID="EUR">{$montant}</ram:GrandTotalAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }
}
