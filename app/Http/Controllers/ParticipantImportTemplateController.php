<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ParticipantImportTemplateController extends Controller
{
    public function __invoke(Request $request, Operation $operation): BinaryFileResponse
    {
        $hasMedical = (bool) ($operation->typeOperation?->formulaire_parcours_therapeutique ?? false);

        $headers = [
            'nom',
            'prenom',
            'email',
            'telephone',
            'adresse_ligne1',
            'code_postal',
            'ville',
            'date_inscription',
            'notes',
        ];

        if ($hasMedical) {
            $headers = array_merge($headers, [
                'date_naissance',
                'sexe',
                'poids_kg',
                'taille_cm',
                'nom_jeune_fille',
                'nationalite',
            ]);
        }

        $filename = 'modele-import-participants.xlsx';
        $tempPath = storage_path('app/temp/'.$filename);

        if (! is_dir(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($tempPath);

        $headerStyle = (new Style)->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        // One example row to illustrate the expected format
        $example = [
            'Dupont',
            'Marie',
            'marie.dupont@exemple.fr',
            '06 12 34 56 78',
            '12 rue de la Paix',
            '75001',
            'Paris',
            '01/09/2025',
            '',
        ];

        if ($hasMedical) {
            $example = array_merge($example, [
                '15/03/1980',
                'F',
                '65',
                '168',
                '',
                'Française',
            ]);
        }

        $writer->addRow(Row::fromValues($example));
        $writer->close();

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
