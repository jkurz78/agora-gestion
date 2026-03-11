<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OperationController extends Controller
{
    public function index(): View
    {
        return view('dashboard'); // Placeholder — will be built in Task 11
    }

    public function create(): View
    {
        return view('dashboard');
    }

    public function store(Request $request)
    {
        return redirect()->route('operations.index');
    }

    public function show(Operation $operation): View
    {
        return view('dashboard');
    }

    public function edit(Operation $operation): View
    {
        return view('dashboard');
    }

    public function update(Request $request, Operation $operation)
    {
        return redirect()->route('operations.index');
    }
}
