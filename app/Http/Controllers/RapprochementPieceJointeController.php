<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RapprochementBancaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class RapprochementPieceJointeController extends Controller
{
    public function __invoke(Request $request, RapprochementBancaire $rapprochement): Response
    {
        if (! $rapprochement->hasPieceJointe()) {
            abort(404);
        }

        $fullPath = $rapprochement->pieceJointeFullPath();

        if ($fullPath === null || ! Storage::disk('local')->exists($fullPath)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $fullPath,
            $rapprochement->piece_jointe_nom,
            ['Content-Type' => $rapprochement->piece_jointe_mime],
            'inline'
        );
    }
}
