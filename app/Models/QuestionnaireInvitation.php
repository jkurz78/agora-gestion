<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutInvitation;
use App\Support\TenantUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QuestionnaireInvitation extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'campaign_id', 'participant_id',
        'token_hash', 'token_chiffre', 'code_court', 'statut', 'sent_at', 'opened_at', 'submitted_at',
    ];

    protected $hidden = ['token_hash', 'token_chiffre'];

    protected function casts(): array
    {
        return [
            'statut' => StatutInvitation::class,
            'token_chiffre' => 'encrypted', // lecture = token clair déchiffré (QR/lien/relance)
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** URL publique de réponse (token clair déchiffré via le cast encrypted). */
    public function lienReponse(): string
    {
        return TenantUrl::route('questionnaire.show', ['token' => $this->token_chiffre]);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireCampaign::class, 'campaign_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(QuestionnaireSubmission::class, 'invitation_id');
    }

    /** Soumission active : en_cours ou soumise (invariant ≤1, voir spec §3.3). */
    public function submissionActive(): ?QuestionnaireSubmission
    {
        return $this->submissions()->whereIn('statut', ['en_cours', 'soumise'])->first();
    }
}
