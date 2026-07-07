<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeQuestion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireCampaignQuestion extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'libelle', 'aide', 'type', 'ordre', 'obligatoire', 'config',
        'grouper_avec_precedente',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeQuestion::class,
            'ordre' => 'integer',
            'obligatoire' => 'boolean',
            'grouper_avec_precedente' => 'boolean',
            'config' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    /** @return array<int, array{libelle: string, valeur: string, ordre: int}> */
    public function options(): array
    {
        return $this->config['options'] ?? [];
    }

    public function libelleOption(string $valeur): ?string
    {
        foreach ($this->options() as $opt) {
            if ($opt['valeur'] === $valeur) {
                return $opt['libelle'];
            }
        }

        return null;
    }

    public function aDesOptions(): bool
    {
        return $this->type->aDesOptions();
    }
}
