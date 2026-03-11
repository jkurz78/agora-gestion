<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class UserController extends Controller
{
    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Utilisateur créé avec succès.');
    }

    public function destroy(User $utilisateur): RedirectResponse
    {
        $utilisateur->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Utilisateur supprimé avec succès.');
    }
}
