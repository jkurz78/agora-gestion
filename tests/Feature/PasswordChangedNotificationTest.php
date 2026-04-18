<?php

declare(strict_types=1);

use App\Mail\PasswordChangedByAdmin;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::clear();
});

it('sends email when admin changes another user password', function () {
    Mail::fake();

    $target = User::factory()->create();
    $target->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    $this->put(route('parametres.utilisateurs.update', $target), [
        'nom' => $target->nom,
        'email' => $target->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    Mail::assertSent(PasswordChangedByAdmin::class, function ($mail) use ($target) {
        return $mail->hasTo($target->email);
    });
});

it('does not send email when password is not changed', function () {
    Mail::fake();

    $target = User::factory()->create();
    $target->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    $this->put(route('parametres.utilisateurs.update', $target), [
        'nom' => 'New Name',
        'email' => $target->email,
    ]);

    Mail::assertNotSent(PasswordChangedByAdmin::class);
});
