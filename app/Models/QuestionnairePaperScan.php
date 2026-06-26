<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class QuestionnairePaperScan extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'invitation_id', 'batch_id',
        'incoming_document_id', 'source', 'chemin_fichier',
        'qr_statut', 'statut',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireInvitation::class, 'invitation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(QuestionnairePaperBatch::class, 'batch_id');
    }

    public function ocrDraft(): HasOne
    {
        return $this->hasOne(QuestionnaireOcrDraft::class, 'scan_id');
    }
}
