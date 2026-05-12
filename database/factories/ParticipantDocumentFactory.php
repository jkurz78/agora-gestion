<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParticipantDocument>
 */
final class ParticipantDocumentFactory extends Factory
{
    protected $model = ParticipantDocument::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'participant_id' => Participant::factory(),
            'label' => $this->faker->randomElement([
                'Attestation médicale',
                'Photo identité',
                'Décharge parentale',
            ]),
            'storage_path' => 'doc-'.$this->faker->uuid().'.pdf',
            'original_filename' => 'document.pdf',
            'source' => 'upload',
        ];
    }
}
