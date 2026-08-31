<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\InfosTechniques;
use Illuminate\View\View;

final class InformationsTechniquesController extends Controller
{
    public function __invoke(InfosTechniques $infos): View
    {
        return view('parametres.informations-techniques', [
            'infos' => $infos->collecter(),
        ]);
    }
}
