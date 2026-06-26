<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSubmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class QuestionnaireSubmission extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'invitation_id', 'statut', 'accepte_contact', 'source', 'submitted_at',
        'remplacee_par_id', 'active_key',
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

    public function remplaceepar(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireSubmission::class, 'remplacee_par_id');
    }

    public function remplacante(): HasOne
    {
        return $this->hasOne(QuestionnaireSubmission::class, 'remplacee_par_id');
    }
}
