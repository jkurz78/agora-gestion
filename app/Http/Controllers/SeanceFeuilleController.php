<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Seance;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SeanceFeuilleController extends Controller
{
    public function download(Operation $operation, Seance $seance): StreamedResponse
    {
        abort_unless((int) $seance->operation_id === (int) $operation->id, 404);
        abort_if($seance->feuille_signee_path === null, 404);

        return Storage::disk('local')->download(
            $seance->feuille_signee_path,
            'feuille-signee-seance-'.$seance->numero.'.pdf',
        );
    }

    public function view(Operation $operation, Seance $seance): StreamedResponse
    {
        abort_unless((int) $seance->operation_id === (int) $operation->id, 404);
        abort_if($seance->feuille_signee_path === null, 404);

        return Storage::disk('local')->response(
            $seance->feuille_signee_path,
            'feuille-signee-seance-'.$seance->numero.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
