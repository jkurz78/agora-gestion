<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Enums\RoleAssociation;
use App\Enums\RoleSysteme;
use App\Models\Association;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

final class SetupForm extends Component
{
    public string $prenom = '';

    public string $nom = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $nomAsso = '';

    /**
     * @return array<string, string|list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'prenom' => 'required|string|max:50',
            'nom' => 'required|string|max:50',
            'email' => 'required|email|max:150|unique:users,email',
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'nomAsso' => 'required|string|max:100',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'prenom.required' => 'Le prénom est obligatoire.',
            'prenom.max' => 'Le prénom ne doit pas dépasser 50 caractères.',
            'nom.required' => 'Le nom est obligatoire.',
            'nom.max' => 'Le nom ne doit pas dépasser 50 caractères.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => "L'email n'est pas valide.",
            'email.max' => "L'email ne doit pas dépasser 150 caractères.",
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'nomAsso.required' => 'Le nom de l\'association est obligatoire.',
            'nomAsso.max' => 'Le nom de l\'association ne doit pas dépasser 100 caractères.',
        ];
    }

    public function submit(): void
    {
        $this->validate();

        [$user, $asso, $redirectTo] = DB::transaction(function (): array {
            if (User::query()
                ->where('role_systeme', RoleSysteme::SuperAdmin)
                ->lockForUpdate()
                ->exists()
            ) {
                return [null, null, '/login'];
            }

            $baseSlug = Str::slug($this->nomAsso);
            $slug = $baseSlug;
            $suffix = 2;

            // The lockForUpdate() above acquires a gap lock on the SuperAdmin range,
            // serializing concurrent fresh-install submits. The slug loop below is
            // therefore effectively single-threaded — no try/catch on UNIQUE needed.
            while (Association::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            $asso = Association::create([
                'nom' => $this->nomAsso,
                'slug' => $slug,
                'statut' => 'actif',
                'forme_juridique' => 'Association loi 1901',
                'exercice_mois_debut' => 9,
                'wizard_completed_at' => null,
            ]);

            $user = User::create([
                'prenom' => $this->prenom,
                'nom' => $this->nom,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'role_systeme' => RoleSysteme::SuperAdmin,
                'derniere_association_id' => $asso->id,
            ]);
            $user->markEmailAsVerified();

            $user->associations()->attach($asso->id, [
                'role' => RoleAssociation::Admin->value,
                'joined_at' => now(),
            ]);

            return [$user, $asso, '/dashboard'];
        });

        if ($user !== null && $asso !== null) {
            Auth::login($user);
            session(['current_association_id' => $asso->id]);
            session()->regenerate();
        }

        $this->redirect($redirectTo);
    }

    public function render(): View
    {
        return view('livewire.setup.setup-form');
    }
}
