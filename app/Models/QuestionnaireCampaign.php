<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutCampagne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireCampaign extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'operation_id', 'template_id',
        'titre_affiche', 'intro', 'remerciement', 'statut', 'ouverte_at', 'cloturee_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagne::class,
            'ouverte_at' => 'datetime',
            'cloturee_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionnaireCampaignQuestion::class, 'campaign_id')->orderBy('ordre');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(QuestionnaireInvitation::class, 'campaign_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(QuestionnaireSubmission::class, 'campaign_id');
    }
}
