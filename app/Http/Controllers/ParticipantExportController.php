<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use Carbon\Carbon;
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
        $confidentiel = $request->boolean('confidentiel')
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar', 'donneesMedicales'])
            ->get();

        $filename = 'participants-'.Str::slug($operation->nom).'-'.now()->format('Y-m-d').'.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($tempPath);

        $headerStyle = (new Style)->withFontBold(true);
        $headers = ['Nom', 'Prénom', 'Adresse', 'Code postal', 'Ville', 'Téléphone', 'Email', 'Date inscription'];
        if ($confidentiel) {
            $headers = array_merge($headers, ['Référé par', 'Date naissance', 'Âge', 'Sexe', 'Taille', 'Poids', 'Notes']);
        }
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        foreach ($participants as $p) {
            $row = [
                $p->tiers->nom ?? '',
                $p->tiers->prenom ?? '',
                $p->tiers->adresse_ligne1 ?? '',
                $p->tiers->code_postal ?? '',
                $p->tiers->ville ?? '',
                $p->tiers->telephone ?? '',
                $p->tiers->email ?? '',
                $p->date_inscription?->format('d/m/Y') ?? '',
            ];
            if ($confidentiel) {
                $row[] = $p->referePar?->displayName() ?? '';
                $med = $p->donneesMedicales;
                $dateNaisRaw = $med?->date_naissance ?? '';
                $dateNaisFormatted = '';
                $age = null;
                if ($dateNaisRaw !== '') {
                    try {
                        $carbon = Carbon::parse($dateNaisRaw);
                        $dateNaisFormatted = $carbon->format('d/m/Y');
                        $age = $carbon->age;
                    } catch (\Throwable) {
                    }
                }
                $taille = $med?->taille !== null && $med->taille !== '' ? (int) $med->taille : null;
                $poids = $med?->poids !== null && $med->poids !== '' ? (int) $med->poids : null;
                $notes = $med?->notes ?? '';
                $notesPlain = '';
                if ($notes !== '') {
                    $text = str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", $notes);
                    $notesPlain = trim(html_entity_decode(strip_tags($text)));
                }

                $row = array_merge($row, [
                    $dateNaisFormatted,
                    $age,
                    $med?->sexe ?? '',
                    $taille,
                    $poids,
                    $notesPlain,
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
