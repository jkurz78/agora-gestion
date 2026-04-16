<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Support\CurrentAssociation;
use Atgp\FacturX\Writer as FacturXWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DocumentPrevisionnelService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function emettre(
        Operation $operation,
        Participant $participant,
        TypeDocumentPrevisionnel $type,
    ): DocumentPrevisionnel {
        $annee = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($annee);

        $seances = Seance::where('operation_id', $operation->id)
            ->orderBy('numero')
            ->get();

        $reglements = Reglement::where('participant_id', $participant->id)
            ->whereIn('seance_id', $seances->pluck('id'))
            ->get()
            ->keyBy('seance_id');

        $lignes = $this->buildLignes($operation, $seances, $reglements, $type);

        $montantTotal = collect($lignes)
            ->where('type', 'montant')
            ->sum('montant');

        // Check if last version has same amounts → return existing
        $lastVersion = DocumentPrevisionnel::where('operation_id', $operation->id)
            ->where('participant_id', $participant->id)
            ->where('type', $type)
            ->orderByDesc('version')
            ->first();

        if ($lastVersion !== null && (float) $lastVersion->montant_total === (float) $montantTotal) {
            $lastMontants = collect($lastVersion->lignes_json)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            $newMontants = collect($lignes)
                ->where('type', 'montant')
                ->pluck('montant')
                ->map(fn ($m) => (float) $m)
                ->values()
                ->toArray();

            if ($lastMontants === $newMontants) {
                return $lastVersion;
            }
        }

        return DB::transaction(function () use ($operation, $participant, $type, $lignes, $montantTotal, $annee): DocumentPrevisionnel {
            $version = (int) DocumentPrevisionnel::where('operation_id', $operation->id)
                ->where('participant_id', $participant->id)
                ->where('type', $type)
                ->max('version') + 1;

            // Count all documents of this type for this exercice for sequential numbering
            $seq = DocumentPrevisionnel::where('type', $type)
                ->where('exercice', $annee)
                ->count() + 1;

            $numero = sprintf('%s-%d-%03d', $type->prefix(), $annee, $seq);

            return DocumentPrevisionnel::create([
                'operation_id' => $operation->id,
                'participant_id' => $participant->id,
                'type' => $type,
                'numero' => $numero,
                'version' => $version,
                'date' => now()->toDateString(),
                'montant_total' => $montantTotal,
                'lignes_json' => $lignes,
                'pdf_path' => null,
                'saisi_par' => Auth::id(),
                'exercice' => $annee,
            ]);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Seance>  $seances
     * @param  Collection<int, Reglement>  $reglements
     * @return array<int, array{type: string, libelle: string, montant?: float, seance_id?: int}>
     */
    private function buildLignes(
        Operation $operation,
        \Illuminate\Database\Eloquent\Collection $seances,
        Collection $reglements,
        TypeDocumentPrevisionnel $type,
    ): array {
        $nbSeances = $seances->count();
        $firstDate = $seances->first()?->date;
        $lastDate = $seances->last()?->date;

        $seanceWord = $nbSeances === 1 ? 'séance' : 'séances';

        $headerLibelle = sprintf(
            '%s du %s au %s en %d %s :',
            $operation->nom,
            $firstDate ? $firstDate->format('d/m/Y') : '—',
            $lastDate ? $lastDate->format('d/m/Y') : '—',
            $nbSeances,
            $seanceWord,
        );

        $lignes = [
            ['type' => 'texte', 'libelle' => $headerLibelle],
        ];

        if ($type === TypeDocumentPrevisionnel::Devis) {
            $total = $reglements->sum('montant_prevu');

            $lignes[] = [
                'type' => 'montant',
                'libelle' => sprintf('%s — %d %s', $operation->nom, $nbSeances, $seanceWord),
                'montant' => (float) $total,
            ];
        } else {
            foreach ($seances as $seance) {
                $reglement = $reglements->get($seance->id);
                $montant = $reglement ? (float) $reglement->montant_prevu : 0.0;

                $lignes[] = [
                    'type' => 'montant',
                    'libelle' => sprintf(
                        'Séance %d — %s',
                        $seance->numero,
                        $seance->date ? $seance->date->format('d/m/Y') : '—',
                    ),
                    'montant' => $montant,
                    'seance_id' => $seance->id,
                ];
            }
        }

        return $lignes;
    }

    public function genererPdf(DocumentPrevisionnel $document): string
    {
        $document->load('participant.tiers', 'operation');

        $association = CurrentAssociation::get();
        $tiers = $document->participant->tiers;

        $headerLogoBase64 = null;
        $headerLogoMime = null;
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $logoContent = Storage::disk('public')->get($association->logo_path);
            if ($logoContent) {
                $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
                $headerLogoMime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
                $headerLogoBase64 = base64_encode($logoContent);
            }
        }

        $pdf = Pdf::loadView('pdf.document-previsionnel', [
            'document' => $document,
            'association' => $association,
            'tiers' => $tiers,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
        ])->setPaper('a4', 'portrait');

        $pdfContent = $pdf->output();

        // Convert to PDF/A-3 with metadata XML
        $xml = $this->genererMetadataXml($document, $association, $tiers);
        $writer = new FacturXWriter;
        $pdfA3Content = $writer->generate($pdfContent, $xml, 'minimum', false);

        // Store on disk
        $path = "documents-previsionnels/{$document->numero}.pdf";
        Storage::disk('local')->put($path, $pdfA3Content);
        $document->update(['pdf_path' => $path]);

        return $pdfA3Content;
    }

    private function genererMetadataXml(
        DocumentPrevisionnel $document,
        ?Association $association,
        Tiers $tiers,
    ): string {
        $numero = htmlspecialchars($document->numero, ENT_XML1, 'UTF-8');
        $date = $document->date->format('Ymd');
        $montant = number_format((float) $document->montant_total, 2, '.', '');
        $sellerName = htmlspecialchars($association?->nom ?? '', ENT_XML1, 'UTF-8');
        $siret = htmlspecialchars($association?->siret ?? '', ENT_XML1, 'UTF-8');
        $buyerName = htmlspecialchars($tiers->displayName(), ENT_XML1, 'UTF-8');

        $siretBlock = '';
        if ($siret !== '') {
            $siretBlock = <<<XML
                <ram:SpecifiedLegalOrganization>
                    <ram:ID schemeID="0002">{$siret}</ram:ID>
                </ram:SpecifiedLegalOrganization>
XML;
        }

        // TypeCode 325 = Pro forma, not 380 (Invoice)
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
        <ram:TypeCode>325</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">{$date}</udt:DateTimeString>
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
                <ram:TaxBasisTotalAmount>{$montant}</ram:TaxBasisTotalAmount>
                <ram:GrandTotalAmount>{$montant}</ram:GrandTotalAmount>
                <ram:DuePayableAmount>{$montant}</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }
}
