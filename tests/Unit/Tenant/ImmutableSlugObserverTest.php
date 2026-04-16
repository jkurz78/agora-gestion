<?php

declare(strict_types=1);

use App\Exceptions\SlugImmutableException;
use App\Models\Association;

it('throws when trying to modify slug without allowSlugChange', function () {
    $asso = Association::factory()->create(['slug' => 'initial-slug']);
    $asso->slug = 'new-slug';

    expect(fn () => $asso->save())->toThrow(SlugImmutableException::class);
});

it('allows slug modification when allowSlugChange flag set on model', function () {
    $asso = Association::factory()->create(['slug' => 'initial-slug']);
    $asso->allowSlugChange = true;
    $asso->slug = 'new-slug-v2';
    $asso->save();

    expect($asso->fresh()->slug)->toBe('new-slug-v2');
});

it('allows initial slug set on create', function () {
    $asso = Association::factory()->create(['slug' => 'brand-new']);
    expect($asso->slug)->toBe('brand-new');
});

it('allows updates on other fields without triggering slug check', function () {
    $asso = Association::factory()->create(['slug' => 'stable', 'nom' => 'Original']);
    $asso->nom = 'Modified';
    $asso->save();
    expect($asso->fresh()->nom)->toBe('Modified');
});
