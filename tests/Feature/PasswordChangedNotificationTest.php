<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Mail\PasswordChangedByAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('sends email when admin changes another user password', function () {
    Mail::fake();

    $admin = User::factory()->create(['role' => RoleAssociation::Admin]);
    $target = User::factory()->create();

    $this->actingAs($admin)->put(route('parametres.utilisateurs.update', $target), [
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

    $admin = User::factory()->create(['role' => RoleAssociation::Admin]);
    $target = User::factory()->create();

    $this->actingAs($admin)->put(route('parametres.utilisateurs.update', $target), [
        'nom' => 'New Name',
        'email' => $target->email,
    ]);

    Mail::assertNotSent(PasswordChangedByAdmin::class);
});
