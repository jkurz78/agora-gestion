<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QuestionnaireOcrDraft extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'scan_id', 'invitation_id', 'payload', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(QuestionnairePaperScan::class, 'scan_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireInvitation::class, 'invitation_id');
    }
}
