<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Mail\PasswordChangedByAdmin;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
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
            'role' => ['nullable', Rule::enum(Role::class)],
        ]);

        User::create([
            'nom' => $validated['nom'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'peut_voir_donnees_sensibles' => $request->boolean('peut_voir_donnees_sensibles'),
            'role' => Role::tryFrom($validated['role'] ?? '') ?? Role::Admin,
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
            'role' => ['nullable', Rule::enum(Role::class)],
        ]);

        $utilisateur->nom = $validated['nom'];
        $utilisateur->email = $validated['email'];

        $passwordChanged = ! empty($validated['password']);

        if ($passwordChanged) {
            $utilisateur->password = $validated['password'];
        }

        $utilisateur->peut_voir_donnees_sensibles = $request->boolean('peut_voir_donnees_sensibles');
        $utilisateur->role = Role::tryFrom($validated['role'] ?? '') ?? $utilisateur->role;

        $utilisateur->save();

        if ($passwordChanged) {
            Mail::to($utilisateur)->send(new PasswordChangedByAdmin(
                user: $utilisateur,
                changedByName: auth()->user()->nom,
            ));
        }

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
