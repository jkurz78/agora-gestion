<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        return view('parametres.utilisateurs.index', [
            'utilisateurs' => User::orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'peut_voir_donnees_sensibles' => ['boolean'],
        ]);

        User::create([
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'peut_voir_donnees_sensibles' => $request->boolean('peut_voir_donnees_sensibles'),
        ]);

        return redirect()->route(request()->attributes->get('espace')->value.'.parametres.utilisateurs.index')
            ->with('success', 'Utilisateur créé.');
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', "unique:users,email,{$utilisateur->id}"],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $utilisateur->nom = $validated['nom'];
        $utilisateur->email = $validated['email'];

        if (! empty($validated['password'])) {
            $utilisateur->password = $validated['password'];
        }

        $utilisateur->peut_voir_donnees_sensibles = $request->boolean('peut_voir_donnees_sensibles');

        $utilisateur->save();

        return redirect()->route(request()->attributes->get('espace')->value.'.parametres.utilisateurs.index')
            ->with('success', 'Utilisateur mis à jour.');
    }

    public function destroy(User $utilisateur): RedirectResponse
    {
        if ($utilisateur->id === auth()->id()) {
            return redirect()->route(request()->attributes->get('espace')->value.'.parametres.utilisateurs.index')
                ->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        $utilisateur->delete();

        return redirect()->route(request()->attributes->get('espace')->value.'.parametres.utilisateurs.index')
            ->with('success', 'Utilisateur supprimé.');
    }
}
