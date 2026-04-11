<?php

declare(strict_types=1);

use App\Models\MessageTemplate;
use App\Models\TypeOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('message_templates table exists with expected columns', function () {
    expect(Schema::hasTable('message_templates'))->toBeTrue();

    foreach (['id', 'nom', 'objet', 'corps', 'type_operation_id', 'created_at', 'updated_at'] as $column) {
        expect(Schema::hasColumn('message_templates', $column))->toBeTrue("Column {$column} missing");
    }
});

it('can create a MessageTemplate with all fields', function () {
    $typeOperation = TypeOperation::factory()->create();

    $template = MessageTemplate::create([
        'nom' => 'Rappel séance',
        'objet' => 'Rappel : votre séance de demain',
        'corps' => 'Bonjour {prenom}, votre prochaine séance est le {date_prochaine_seance}.',
        'type_operation_id' => $typeOperation->id,
    ]);

    expect($template->id)->toBeInt()
        ->and($template->nom)->toBe('Rappel séance')
        ->and($template->objet)->toBe('Rappel : votre séance de demain')
        ->and($template->corps)->toContain('{prenom}')
        ->and($template->type_operation_id)->toBe($typeOperation->id);
});

it('MessageTemplate belongs to TypeOperation', function () {
    $typeOperation = TypeOperation::factory()->create();

    $template = MessageTemplate::create([
        'nom' => 'Gabarit atelier',
        'objet' => 'Info atelier',
        'corps' => 'Corps du message',
        'type_operation_id' => $typeOperation->id,
    ]);

    expect($template->typeOperation)->toBeInstanceOf(TypeOperation::class)
        ->and($template->typeOperation->id)->toBe($typeOperation->id);
});

it('MessageTemplate type_operation_id is nullable for global templates', function () {
    $template = MessageTemplate::create([
        'nom' => 'Gabarit global',
        'objet' => 'Info générale',
        'corps' => 'Corps du message global',
        'type_operation_id' => null,
    ]);

    expect($template->type_operation_id)->toBeNull()
        ->and($template->typeOperation)->toBeNull();
});
