<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireAnswer extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'submission_id', 'campaign_question_id',
        'value_text', 'value_integer', 'value_boolean', 'value_option', 'value_meta',
    ];

    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_boolean' => 'boolean',
            'value_meta' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireSubmission::class, 'submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaignQuestion::class, 'campaign_question_id');
    }
}
