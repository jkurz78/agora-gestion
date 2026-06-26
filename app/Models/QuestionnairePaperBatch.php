<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnairePaperBatch extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'type', 'cree_par',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(QuestionnairePaperScan::class, 'batch_id');
    }
}
