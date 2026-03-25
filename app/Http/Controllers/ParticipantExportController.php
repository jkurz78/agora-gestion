<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ParticipantExportController extends Controller
{
    public function __invoke(Request $request, Operation $operation): BinaryFileResponse
    {
        $canSeeSensible = (bool) ($request->user()->peut_voir_donnees_sensibles ?? false);

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar', 'donneesMedicales'])
            ->get();

        $filename = 'participants-'.Str::slug($operation->nom).'-'.now()->format('Y-m-d').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerStyle = (new Style())->withFontBold();
        $headers = ['Nom', 'Prénom', 'Téléphone', 'Email', 'Date inscription', 'Référé par'];
        if ($canSeeSensible) {
            $headers = array_merge($headers, ['Date naissance', 'Sexe', 'Taille', 'Poids']);
        }
        $writer->addRow(Row::fromValues($headers, $headerStyle));

        foreach ($participants as $p) {
            $row = [
                $p->tiers->nom ?? '',
                $p->tiers->prenom ?? '',
                $p->tiers->telephone ?? '',
                $p->tiers->email ?? '',
                $p->date_inscription?->format('d/m/Y') ?? '',
                $p->referePar?->displayName() ?? '',
            ];
            if ($canSeeSensible) {
                $med = $p->donneesMedicales;
                $row = array_merge($row, [
                    $med?->date_naissance ?? '',
                    $med?->sexe ?? '',
                    $med?->taille ?? '',
                    $med?->poids ?? '',
                ]);
            }
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
