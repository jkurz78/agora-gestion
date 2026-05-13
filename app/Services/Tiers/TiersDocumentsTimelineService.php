<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Models\Adhesion;
use App\Models\DocumentPrevisionnel;
use App\Models\Facture;
use App\Models\FacturePartenaireDeposee;
use App\Models\ParticipantDocument;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\DocumentParticipantLigneDTO;
use App\Services\Tiers\DTO\DocumentPrevisionnelLigneDTO;
use App\Services\Tiers\DTO\DocumentsTimelineDTO;
use App\Services\Tiers\DTO\FactureDeposeeLigneDTO;
use App\Services\Tiers\DTO\FactureEmiseLigneDTO;
use App\Services\Tiers\DTO\PieceJointeLigneDTO;
use App\Services\Tiers\DTO\RecuFiscalLigneDTO;
use Illuminate\Support\Carbon;

final class TiersDocumentsTimelineService
{
    public function forTiers(Tiers $tiers): DocumentsTimelineDTO
    {
        $recus = $this->recusFiscaux($tiers);
        $facturesEmises = $this->facturesEmises($tiers);
        $facturesDeposees = $this->facturesDeposees($tiers);
        $justificatifs = $this->justificatifsParticipants($tiers);
        $piecesJointes = $this->piecesJointes($tiers);
        $documentsPrevisionnels = $this->documentsPrevisionnels($tiers);

        return new DocumentsTimelineDTO(
            recusFiscaux: $recus,
            facturesEmises: $facturesEmises,
            facturesDeposees: $facturesDeposees,
            justificatifsParticipants: $justificatifs,
            piecesJointes: $piecesJointes,
            documentsPrevisionnels: $documentsPrevisionnels,
            totalGlobal: count($recus) + count($facturesEmises) + count($facturesDeposees)
                + count($justificatifs) + count($piecesJointes) + count($documentsPrevisionnels),
        );
    }

    public function countTotal(Tiers $tiers): int
    {
        $a = RecuFiscalEmis::where('tiers_id', $tiers->id)->whereNull('annule_at')->count();
        $b = Facture::where('tiers_id', $tiers->id)->count();
        $c = FacturePartenaireDeposee::where('tiers_id', $tiers->id)->count();
        $d = ParticipantDocument::whereIn('participant_id', $tiers->participants()->select('id'))->count();
        $e = $this->countPiecesJointes($tiers);
        $f = DocumentPrevisionnel::whereIn('participant_id', $tiers->participants()->select('id'))->count();

        return $a + $b + $c + $d + $e + $f;
    }

    /** @return RecuFiscalLigneDTO[] */
    private function recusFiscaux(Tiers $tiers): array
    {
        $recus = RecuFiscalEmis::query()
            ->where('tiers_id', $tiers->id)
            ->whereNull('annule_at')
            ->with(['transactionLigne:id,transaction_id'])
            ->orderByDesc('emitted_at')
            ->get();

        $transactionIds = $recus->pluck('transactionLigne.transaction_id')->filter()->unique()->values();

        $adhesionTransactionIds = $transactionIds->isEmpty()
            ? collect()
            : Adhesion::whereIn('transaction_id', $transactionIds)->pluck('transaction_id');

        return $recus->map(function (RecuFiscalEmis $recu) use ($adhesionTransactionIds, $tiers): RecuFiscalLigneDTO {
            $txId = $recu->transactionLigne?->transaction_id;
            $estCotisation = $txId !== null && $adhesionTransactionIds->contains($txId);
            $type = $estCotisation ? 'cotisation' : 'don';

            return new RecuFiscalLigneDTO(
                id: (int) $recu->id,
                numero: (string) $recu->numero,
                type: $type,
                dateEmission: Carbon::parse($recu->emitted_at),
                montant: (float) $recu->montant_centimes / 100,
                downloadUrl: route('tiers.recu-fiscal.download', ['recu' => $recu->id]),
                sourceUrl: $estCotisation
                    ? route('tiers.show', ['tiers' => $tiers->id]).'?onglet=adhesion'
                    : route('tiers.show', ['tiers' => $tiers->id]).'?onglet=dons',
            );
        })->all();
    }

    /** @return FactureEmiseLigneDTO[] */
    private function facturesEmises(Tiers $tiers): array
    {
        return Facture::query()
            ->where('tiers_id', $tiers->id)
            ->orderByDesc('date')
            ->get()
            ->map(function (Facture $f): FactureEmiseLigneDTO {
                return new FactureEmiseLigneDTO(
                    id: (int) $f->id,
                    numero: (string) $f->numero,
                    date: Carbon::parse($f->date),
                    type: (string) ($f->type ?? 'facture'),
                    statut: $f->statut instanceof \BackedEnum ? $f->statut->value : (string) $f->statut,
                    montantTtc: (float) ($f->montant_total ?? 0),
                    ficheUrl: route('facturation.factures.show', ['facture' => $f->id]),
                );
            })->all();
    }

    /** @return FactureDeposeeLigneDTO[] */
    private function facturesDeposees(Tiers $tiers): array
    {
        return FacturePartenaireDeposee::query()
            ->where('tiers_id', $tiers->id)
            ->orderByDesc('date_facture')
            ->get()
            ->map(function (FacturePartenaireDeposee $f): FactureDeposeeLigneDTO {
                return new FactureDeposeeLigneDTO(
                    id: (int) $f->id,
                    numeroFournisseur: (string) $f->numero_facture,
                    dateFacture: Carbon::parse($f->date_facture),
                    statut: $f->statut instanceof \BackedEnum ? (string) $f->statut->value : (string) $f->statut,
                    pdfTaille: (int) $f->pdf_taille,
                    dateDepot: Carbon::parse($f->created_at),
                    downloadUrl: route('comptabilite.factures-fournisseurs.pdf', ['depot' => $f->id]),
                    ficheUrl: route('comptabilite.factures-fournisseurs.index'),
                );
            })->all();
    }

    /** @return DocumentParticipantLigneDTO[] */
    private function justificatifsParticipants(Tiers $tiers): array
    {
        return ParticipantDocument::query()
            ->whereIn('participant_id', $tiers->participants()->select('id'))
            ->with('participant.tiers:id,nom,prenom')
            ->latest()
            ->get()
            ->map(function (ParticipantDocument $d): DocumentParticipantLigneDTO {
                $nom = trim((string) ($d->participant->tiers->prenom ?? '').' '.($d->participant->tiers->nom ?? ''));
                $filename = basename((string) $d->storage_path);

                return new DocumentParticipantLigneDTO(
                    id: (int) $d->id,
                    label: (string) $d->label,
                    participantId: (int) $d->participant_id,
                    participantNom: $nom,
                    source: (string) $d->source,
                    dateDepot: Carbon::parse($d->created_at),
                    downloadUrl: route('operations.participants.documents.download', [
                        'participant' => $d->participant_id,
                        'filename' => $filename,
                    ]),
                );
            })->all();
    }

    /** @return PieceJointeLigneDTO[] */
    private function piecesJointes(Tiers $tiers): array
    {
        $txLignes = TransactionLigne::query()
            ->whereHas('transaction', fn ($q) => $q->where('tiers_id', $tiers->id))
            ->whereNotNull('piece_jointe_path')
            ->with('transaction:id,type,date,libelle,tiers_id')
            ->get();

        $tx = Transaction::query()
            ->where('tiers_id', $tiers->id)
            ->whereNotNull('piece_jointe_path')
            ->get(['id', 'type', 'date', 'libelle']);

        $lignesDtos = $txLignes->map(fn (TransactionLigne $ligne) => new PieceJointeLigneDTO(
            transactionId: (int) $ligne->transaction_id,
            ligneId: (int) $ligne->id,
            dateTransaction: Carbon::parse($ligne->transaction->date),
            type: $ligne->transaction->type instanceof \BackedEnum
                ? $ligne->transaction->type->value
                : (string) $ligne->transaction->type,
            libelle: (string) $ligne->transaction->libelle,
            niveau: 'ligne',
            downloadUrl: route('comptabilite.transactions.piece-jointe-ligne', [
                'transaction' => $ligne->transaction_id,
                'ligne' => $ligne->id,
            ]),
        ));

        $txDtos = $tx->map(fn (Transaction $t) => new PieceJointeLigneDTO(
            transactionId: (int) $t->id,
            ligneId: null,
            dateTransaction: Carbon::parse($t->date),
            type: $t->type instanceof \BackedEnum ? $t->type->value : (string) $t->type,
            libelle: (string) $t->libelle,
            niveau: 'transaction',
            downloadUrl: route('transactions.piece-jointe', ['transaction' => $t->id]),
        ));

        return $lignesDtos->concat($txDtos)
            ->sortByDesc(fn (PieceJointeLigneDTO $p) => $p->dateTransaction->timestamp)
            ->values()
            ->all();
    }

    /** @return DocumentPrevisionnelLigneDTO[] */
    private function documentsPrevisionnels(Tiers $tiers): array
    {
        return DocumentPrevisionnel::query()
            ->whereIn('participant_id', $tiers->participants()->select('id'))
            ->with([
                'participant:id,tiers_id',
                'participant.tiers:id,nom,prenom',
                'operation:id,nom',
            ])
            ->orderByDesc('date')
            ->orderByDesc('version')
            ->get()
            ->map(function (DocumentPrevisionnel $d): DocumentPrevisionnelLigneDTO {
                $tiersRelation = $d->participant?->tiers;
                $participantNom = $tiersRelation
                    ? trim((string) ($tiersRelation->prenom ?? '').' '.($tiersRelation->nom ?? ''))
                    : '?';

                return new DocumentPrevisionnelLigneDTO(
                    id: (int) $d->id,
                    numero: (string) $d->numero,
                    type: $d->type,
                    version: (int) $d->version,
                    date: Carbon::parse($d->date),
                    montantTotal: (float) $d->montant_total,
                    operationId: (int) $d->operation_id,
                    operationNom: (string) ($d->operation->nom ?? ''),
                    participantId: (int) $d->participant_id,
                    participantNom: $participantNom,
                    downloadUrl: route('operations.documents-previsionnels.pdf', ['document' => $d->id]),
                );
            })
            ->all();
    }

    private function countPiecesJointes(Tiers $tiers): int
    {
        $nbLignes = TransactionLigne::query()
            ->whereHas('transaction', fn ($q) => $q->where('tiers_id', $tiers->id))
            ->whereNotNull('piece_jointe_path')
            ->count();

        $nbTx = Transaction::query()
            ->where('tiers_id', $tiers->id)
            ->whereNotNull('piece_jointe_path')
            ->count();

        return $nbLignes + $nbTx;
    }
}
