<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NoteDeFraisPieceJointeController extends Controller
{
    public function __invoke(NoteDeFrais $noteDeFrais, NoteDeFraisLigne $ligne): StreamedResponse
    {
        Gate::authorize('treat', $noteDeFrais);

        // Defensive: ensure the ligne belongs to this NDF
        if ((int) $ligne->note_de_frais_id !== (int) $noteDeFrais->id) {
            abort(404);
        }

        if ($ligne->piece_jointe_path === null || $ligne->piece_jointe_path === '') {
            abort(404);
        }

        if (! Storage::disk('local')->exists($ligne->piece_jointe_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($ligne->piece_jointe_path);
    }
}
