<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ParticipantExportController extends Controller
{
    public function __invoke(Request $request, Operation $operation): Response
    {
        // TODO: implement with openspout in Task 6
        abort(501, 'Export not yet implemented');
    }
}
