<?php

declare(strict_types=1);

namespace App\Livewire\SuperAdmin;

use App\Enums\RoleSysteme;
use App\Mail\SuperAdminInvitationMail;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class AssociationCreateForm extends Component
{
    #[Validate('required|string|min:2|max:255')]
    public string $nom = '';

    #[Validate('required|string|regex:/^[a-z0-9-]+$/|max:64|unique:association,slug')]
    public string $slug = '';

    #[Validate('required|email')]
    public string $email_admin = '';

    #[Validate('required|string|min:2|max:255')]
    public string $nom_admin = '';

    public function submit(): mixed
    {
        $this->validate();

        [$association, $admin, $token] = DB::transaction(function (): array {
            $association = Association::create([
                'nom' => $this->nom,
                'slug' => $this->slug,
                'statut' => 'actif',
            ]);

            $admin = User::create([
                'nom' => $this->nom_admin,
                'email' => $this->email_admin,
                'password' => bcrypt(Str::random(40)),
                'role_systeme' => RoleSysteme::User,
            ]);

            $admin->associations()->attach($association->id, [
                'role' => 'admin',
                'joined_at' => now(),
            ]);

            $token = Password::broker()->createToken($admin);

            SuperAdminAccessLog::create([
                'user_id' => auth()->id(),
                'association_id' => $association->id,
                'action' => 'create_association',
                'payload' => ['admin_email' => $this->email_admin],
                'created_at' => now(),
            ]);

            return [$association, $admin, $token];
        });

        Mail::to($this->email_admin)->send(new SuperAdminInvitationMail(
            association: $association,
            email: $this->email_admin,
            nomAdmin: $this->nom_admin,
            resetToken: $token,
        ));

        session()->flash('success', "Association '{$association->nom}' créée et invitation envoyée à {$this->email_admin}.");

        return redirect()->route('super-admin.associations.index');
    }

    public function render(): View
    {
        return view('livewire.super-admin.association-create-form');
    }
}
