<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RemiseBancaire;
use Symfony\Component\HttpFoundation\Response;

final class RemiseBancairePdfController extends Controller
{
    public function __invoke(RemiseBancaire $remise): Response
    {
        // TODO: Task 10 — full PDF implementation
        abort(501, 'PDF generation not yet implemented.');
    }
}
