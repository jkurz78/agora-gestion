<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Membre;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MembreController extends Controller
{
    public function index(): View
    {
        return view('dashboard'); // Placeholder — will be built in Task 10
    }

    public function create(): View
    {
        return view('dashboard');
    }

    public function store(Request $request)
    {
        // Placeholder — will be built in Task 10
        return redirect()->route('membres.index');
    }

    public function show(Membre $membre): View
    {
        return view('dashboard');
    }

    public function edit(Membre $membre): View
    {
        return view('dashboard');
    }

    public function update(Request $request, Membre $membre)
    {
        return redirect()->route('membres.index');
    }

    public function destroy(Membre $membre)
    {
        return redirect()->route('membres.index');
    }
}
