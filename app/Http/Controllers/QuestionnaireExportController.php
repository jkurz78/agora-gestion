<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class QuestionnaireExportController extends Controller
{
    public function __invoke(QuestionnaireCampaign $campagne, QuestionnaireExcelExporter $exporter): BinaryFileResponse
    {
        $filename = 'questionnaire-'.Str::slug($campagne->titre_affiche).'-'.now()->format('Y-m-d').'.xlsx';
        $dir = storage_path('app/temp');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.$filename;

        $exporter->ecrire($campagne, $path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
