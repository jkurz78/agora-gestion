<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\View\View;

final class ParametreController extends Controller
{
    public function index(): View
    {
        return view('parametres.index', [
            'categories' => Categorie::with('sousCategories')->orderBy('nom')->get(),
            'comptesBancaires' => CompteBancaire::orderBy('nom')->get(),
            'utilisateurs' => User::orderBy('nom')->get(),
        ]);
    }
}
