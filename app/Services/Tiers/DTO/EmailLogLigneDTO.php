<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\EmailLog;
use Illuminate\Support\Carbon;

final readonly class EmailLogLigneDTO
{
    public function __construct(
        public int $id,
        public Carbon $dateEnvoi,
        public string $categorie,
        public string $objet,
        public string $destinataire,
        public string $statut,
        public ?string $erreurMessage,
        public int $nbOuvertures,
        public ?Carbon $premiereOuvertureAt,
        public bool $aPieceJointe,
        public ?string $attachmentNom,
        public ?int $participantId,
        public ?string $participantNom,
        public ?int $operationId,
        public ?string $operationNom,
        public ?string $campagneNom,
        public ?string $envoyeParNom,
    ) {}

    public static function fromEmailLog(EmailLog $log): self
    {
        $destinataire = $log->destinataire_nom
            ? sprintf('%s <%s>', $log->destinataire_nom, $log->destinataire_email)
            : (string) $log->destinataire_email;

        $opens = $log->opens;
        $nb = $opens->count();
        $premiere = $nb > 0
            ? Carbon::parse($opens->min('opened_at'))
            : null;

        $aPj = ! empty($log->attachment_path);
        $attachmentNom = $aPj ? basename((string) $log->attachment_path) : null;

        $participantNom = null;
        if ($log->participant) {
            $participantNom = trim(((string) $log->participant->prenom).' '.((string) $log->participant->nom));
        }

        return new self(
            id: (int) $log->id,
            dateEnvoi: Carbon::parse($log->created_at),
            categorie: (string) $log->categorie,
            objet: $log->objet_rendu ?: (string) $log->objet,
            destinataire: $destinataire,
            statut: (string) $log->statut,
            erreurMessage: $log->erreur_message,
            nbOuvertures: $nb,
            premiereOuvertureAt: $premiere,
            aPieceJointe: $aPj,
            attachmentNom: $attachmentNom,
            participantId: $log->participant_id !== null ? (int) $log->participant_id : null,
            participantNom: $participantNom,
            operationId: $log->operation_id !== null ? (int) $log->operation_id : null,
            operationNom: $log->operation?->nom,
            campagneNom: $log->campagne?->nom,
            envoyeParNom: $log->envoyePar?->name,
        );
    }
}
