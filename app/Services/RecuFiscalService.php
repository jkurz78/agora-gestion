<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

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

        // Garde montant > 0 : un don ou une cotisation à 0€ (palier HelloAsso "offert"
        // par exemple) ne peut pas donner droit à un reçu fiscal — pas de versement.
        if ((float) $ligne->montant <= 0) {
            throw RecuFiscalException::montantNul();
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
            $objet = $this->determinerObjetRecu($sousCat);

            $pdfBinaire = $this->genererPdfBinaire($asso, $tiers, $ligne, $numero, $articleCgi, $formeDon, $modeVersement, $objet);
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

    public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, ?User $user = null): RecuFiscalEmis
    {
        $this->validerEligibiliteAdhesion($adhesion);
        $ligne = $this->resoudreLigneCotisation($adhesion);

        return $this->obtenirOuGenerer($ligne, $user);
    }

    public function validerEligibiliteAdhesion(Adhesion $adhesion): void
    {
        if ($adhesion->transaction_id === null) {
            throw RecuFiscalException::adhesionGratuite();
        }
        if (! $adhesion->deductible_fiscal) {
            throw RecuFiscalException::adhesionNonDeductible();
        }
        $ligne = $this->resoudreLigneCotisation($adhesion);
        $this->validerEligibilite($ligne);
    }

    public function annuler(RecuFiscalEmis $recu, string $motif, ?User $user = null): void
    {
        if ($recu->isAnnule()) {
            return;
        }

        $recu->update([
            'annule_at' => now(),
            'annule_motif' => $motif,
        ]);
    }

    public function reemettre(RecuFiscalEmis $ancien, string $motif, ?User $user = null): RecuFiscalEmis
    {
        return DB::transaction(function () use ($ancien, $motif, $user) {
            $this->annuler($ancien, $motif, $user);

            $ligne = $ancien->transactionLigne;
            $nouveau = $this->obtenirOuGenerer($ligne, $user);

            $ancien->update(['remplace_par_id' => $nouveau->id]);

            return $nouveau;
        });
    }

    public function streamPdf(RecuFiscalEmis $recu): Response
    {
        if (! $recu->verifierIntegrite()) {
            throw new \RuntimeException("Intégrité du PDF reçu n°{$recu->numero} compromise — hash incorrect");
        }

        $filename = "recu-fiscal-{$recu->numero}.pdf";
        $contents = Storage::disk('local')->get($recu->pdfFullPath());

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function resoudreLigneCotisation(Adhesion $adhesion): TransactionLigne
    {
        if ($adhesion->transaction_id === null) {
            throw RecuFiscalException::adhesionGratuite();
        }
        $lignes = $adhesion->transaction->lignes()->get();

        if ($adhesion->formuleAdhesion?->est_helloasso) {
            $ligne = $lignes->firstWhere('helloasso_tier_id', $adhesion->formuleAdhesion->helloasso_tier_id);
            if ($ligne !== null) {
                return $ligne;
            }
        }

        if ($lignes->count() === 1) {
            return $lignes->first();
        }

        if ($adhesion->formuleAdhesion !== null) {
            $ligne = $lignes->firstWhere('sous_categorie_id', $adhesion->formuleAdhesion->sous_categorie_id);
            if ($ligne !== null) {
                return $ligne;
            }
        }

        throw new RecuFiscalException("Impossible d'identifier la ligne cotisation de l'adhésion #{$adhesion->id}.");
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

    private function determinerObjetRecu(SousCategorie $sc): string
    {
        return $sc->hasUsage(UsageComptable::Cotisation) ? 'cotisation' : 'don';
    }

    private function genererPdfBinaire(
        Association $asso,
        Tiers $donateur,
        TransactionLigne $ligne,
        string $numero,
        string $articleCgi,
        string $formeDon,
        string $modeVersement,
        string $objet = 'don',
    ): string {
        $montantFloat = (float) $ligne->montant;
        $montantFormate = number_format($montantFloat, 2, ',', ' ').' €';
        $montantEnLettres = app(MontantEnLettresService::class)->convertir($montantFloat);

        $articleCgiLibelle = match ($articleCgi) {
            'art_200' => 'article 200',
            'art_238_bis' => 'article 238 bis',
            default => $articleCgi,
        };

        $numeroCgi = match ($articleCgi) {
            'art_200' => '200',
            'art_238_bis' => '238 bis',
            default => $articleCgi,
        };

        $estMecenatEntreprise = $articleCgi === 'art_238_bis' && $donateur->type === 'entreprise';
        $estCotisation = $objet === 'cotisation';

        $titreDocument = match (true) {
            $estCotisation => "Reçu au titre d'une cotisation à un organisme d'intérêt général",
            $estMecenatEntreprise => "Reçu au titre du mécénat d'entreprise",
            default => "Reçu au titre des dons à certains organismes d'intérêt général",
        };

        $contexteSpecifique = $estMecenatEntreprise
            ? "Versement effectué dans le cadre du mécénat d'entreprise prévu à l'article 238 bis du CGI."
            : null;

        $formeLibelle = match (true) {
            $estCotisation => 'Cotisation versée par le membre',
            $formeDon === 'abandon_revenus' => "Le donateur renonce expressément au remboursement des frais engagés dans le cadre de son activité bénévole et entend en faire don à l'association.",
            default => 'Don manuel en numéraire',
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
            'numeroCgi' => $numeroCgi,
            'titreDocument' => $titreDocument,
            'contexteSpecifique' => $contexteSpecifique,
            'formeLibelle' => $formeLibelle,
            'modeLibelle' => $modeLibelle,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
            'objet' => $objet,
        ])->setPaper('a4', 'portrait');

        return $pdf->output();
    }
}
