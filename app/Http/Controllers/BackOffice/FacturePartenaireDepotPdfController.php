<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\FacturePartenaireDeposee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class FacturePartenaireDepotPdfController extends Controller
{
    public function __invoke(Request $request, FacturePartenaireDeposee $depot): Response
    {
        if (! Storage::disk('local')->exists($depot->pdf_path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $depot->pdf_path,
            'Facture '.$depot->numero_facture.'.pdf',
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }
}
