<?php

declare(strict_types=1);

use App\Models\User;

test('gestion dashboard loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});
