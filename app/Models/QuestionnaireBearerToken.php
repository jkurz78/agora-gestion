<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class QuestionnaireBearerToken extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'campaign_id',
        'token_hash',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(QuestionnaireSubmission::class, 'bearer_token_id');
    }

    public function submissionActive(): HasOne
    {
        return $this->hasOne(QuestionnaireSubmission::class, 'bearer_token_id')
            ->whereNull('remplacee_par_id');
    }
}
