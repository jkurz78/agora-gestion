<?php

declare(strict_types=1);

namespace App\Services\Portail;

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

            Storage::disk('local')->putFileAs(dirname($pdfPath), $pdf, basename($pdfPath));

            $depot = FacturePartenaireDeposee::create([
                'association_id' => $tiers->association_id,
                'tiers_id' => $tiers->id,
                'date_facture' => $date->toDateString(),
                'numero_facture' => $numero,
                'pdf_path' => $pdfPath,
                'pdf_taille' => $pdf->getSize(),
                'statut' => 'soumise',
            ]);

            Log::info('portail.facture-partenaire.deposee', [
                'depot_id' => $depot->id,
                'tiers_id' => $tiers->id,
                'numero' => $depot->numero_facture,
            ]);

            return $depot;
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
