<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSubmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireSubmission extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'invitation_id', 'statut', 'accepte_contact', 'source', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutSubmission::class,
            'accepte_contact' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireInvitation::class, 'invitation_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuestionnaireAnswer::class, 'submission_id');
    }
}
