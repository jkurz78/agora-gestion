<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FacturePartenaireService
{
    public function submit(Tiers $tiers, array $data, UploadedFile $pdf): FacturePartenaireDeposee
    {
        return DB::transaction(function () use ($tiers, $data, $pdf): FacturePartenaireDeposee {
            $numero = trim((string) $data['numero_facture']);
            $date = Carbon::parse($data['date_facture']);

            $pdfPath = $this->buildPdfPath($tiers, $numero, $date);

            $stored = Storage::disk('local')->putFileAs(dirname($pdfPath), $pdf, basename($pdfPath));
            if ($stored === false) {
                throw new \RuntimeException("Échec de l'écriture du fichier PDF : {$pdfPath}");
            }

            $depot = FacturePartenaireDeposee::create([
                'association_id' => $tiers->association_id,
                'tiers_id' => $tiers->id,
                'date_facture' => $date->toDateString(),
                'numero_facture' => $numero,
                'pdf_path' => $pdfPath,
                'pdf_taille' => $pdf->getSize(),
                'statut' => StatutFactureDeposee::Soumise,
            ]);

            Log::info('portail.facture-partenaire.deposee', [
                'depot_id' => $depot->id,
                'tiers_id' => $tiers->id,
                'numero' => $depot->numero_facture,
            ]);

            return $depot;
        });
    }

    /**
     * Hard-delete a depot (BDD + fichier physique).
     *
     * Ordre choisi (Option A) : delete BDD en premier, puis fichier.
     * Si le fichier reste orphelin après un crash entre les deux, c'est
     * un problème mineur (nettoyable par cron). L'inverse (Option B) laisserait
     * un enregistrement BDD orphelin sans fichier, état plus difficile à corriger.
     *
     * Guards :
     *  – cross-tiers   : DomainException si $depot->tiers_id ≠ $tiers->id.
     *  – statut        : DomainException si statut ≠ Soumise (déjà traité/rejeté).
     */
    public function oublier(FacturePartenaireDeposee $depot, Tiers $tiers): void
    {
        if ((int) $depot->tiers_id !== (int) $tiers->id) {
            throw new \DomainException('Ce dépôt n\'appartient pas au tiers fourni.');
        }

        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            throw new \DomainException('Seul un dépôt au statut « soumise » peut être supprimé.');
        }

        DB::transaction(function () use ($depot, $tiers): void {
            $pdfPath = $depot->pdf_path; // capture before delete
            $depotId = $depot->id;       // capture before delete

            $depot->delete(); // hard delete (no SoftDeletes on this model)

            Storage::disk('local')->delete($pdfPath); // best-effort; silent if file absent

            Log::info('portail.facture-partenaire.oubliee', [
                'depot_id' => $depotId,
                'tiers_id' => $tiers->id,
            ]);
        });
    }

    private function buildPdfPath(Tiers $tiers, string $numero, Carbon $date): string
    {
        $assocId = $tiers->association_id;
        $year = $date->format('Y');
        $month = $date->format('m');
        $datePrefix = $date->format('Y-m-d');
        $numeroSlug = Str::substr(Str::slug($numero), 0, 30);
        $rand6 = Str::lower(Str::random(6));

        return "associations/{$assocId}/factures-deposees/{$year}/{$month}/{$datePrefix}-{$numeroSlug}-{$rand6}.pdf";
    }
}
