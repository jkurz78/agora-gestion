<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\FacturePartenaireDeposee;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class FacturePartenaireDeposeePdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $depot = FacturePartenaireDeposee::findOrFail((int) $request->route('depot'));

        $tiers = Auth::guard('tiers-portail')->user();

        abort_unless((int) $depot->tiers_id === (int) $tiers?->id, 403);
        abort_unless((int) $depot->association_id === (int) TenantContext::currentId(), 403);

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
