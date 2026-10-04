<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

/**
 * Le gel d'une pièce dont une ligne est rattachée à l'exercice suivant (CCA/PCA, spec § 7), et celui
 * d'une écriture de rattachement.
 *
 * Les gestes qui écrivent sur une pièce, ou sur l'une de ses lignes, s'y réfèrent plutôt que de relire
 * eux-mêmes les rattachements : assertLigneNonGelee() (un geste sur UNE ligne), verrouillerPieceNonGelee()
 * (un geste qui réécrit la pièce entière) et verrouillerPieceExtournable() (l'annulation par extourne,
 * refusée selon sa date, et toujours pour une écriture de rattachement). Elles refusent par défaut. Les
 * deux premières partagent le message MOTIF_PIECE_GELEE ; la troisième, dont le refus dépend d'une date,
 * formule le sien (motifExtournePrecoce()). Une écriture de rattachement est refusée par les trois, avec
 * MOTIF_ECRITURE_DE_RATTACHEMENT.
 *
 * Classe à part du service de rattachement (RattachementExerciceService), volontairement : elle ne dépend
 * que d'ExerciceService et de VerrouLotsCanoniques, qui n'ont eux-mêmes aucune dépendance. Les gestes
 * protégés (TransactionService, TransactionExtourneService, ReclassementLigneService, la ventilation et la
 * synchronisation HelloAsso) la reçoivent sans atteindre ProvisionPDService ni EcritureGenerator, qui ne
 * servent qu'à créer et défaire un rattachement : le risque d'un cycle d'injection disparaît par
 * construction, et non parce qu'un test le surveille. Le test des constructeurs le vérifie quand même.
 * Le gel lit les rattachements ; il n'en écrit jamais.
 *
 * Référence : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md (§ 7, D13).
 */
final class GelRattachement
{
    /**
     * Le message unique du verrou (spec § 7), destiné à TOUS les sites qui refusent d'écrire sur
     * une pièce dont une ligne est rattachée : reclassement, retrait de ligne, édition, suppression,
     * annulation, ventilation, synchronisation HelloAsso. Posé aujourd'hui sur le reclassement, le
     * retrait de ligne, l'édition, la suppression et l'annulation ; les autres sites suivent (Tasks 8c
     * et 9). Il nomme le constat, l'écran où se défait le rattachement et la sortie : le retirer,
     * corriger, le repasser. Un site qui en formulerait un autre rendrait le refus illisible d'un écran
     * à l'autre. Seule l'annulation par extourne a le sien (motifExtournePrecoce()) : elle n'est refusée
     * que selon sa date, et sa sortie n'est pas « corriger, puis repasser ».
     */
    public const MOTIF_PIECE_GELEE = 'Cette pièce porte un rattachement à l’exercice suivant : pour la modifier, retirez d’abord le rattachement '
        .'depuis Exercices > Écritures de rattachement, corrigez, puis repassez-le.';

    /**
     * Le refus des gestes qui réécrivent une pièce (édition, suppression, annulation, annulation par
     * extourne) ou écrivent sur une de ses lignes (reclassement) quand cette pièce EST une écriture de
     * rattachement, dotation ou extourne générée. Distinct de
     * MOTIF_PIECE_GELEE : il n'y a rien à retirer puis repasser sur cette pièce, c'est le
     * rattachement qui la porte, et lui seul la défait avec sa jumelle. La supprimer ou l'annuler ici
     * romprait la paire dotation / extourne : le résultat de N ou de N+1 serait faux, et le retrait ne
     * retrouverait plus l'écriture supprimée logiquement (ProvisionPDService::supprimer() ne la voit pas,
     * et `provision_id` passerait à nul avec le rattachement). La modifier ferait diverger ses montants
     * de ceux du rattachement.
     */
    public const MOTIF_ECRITURE_DE_RATTACHEMENT = 'Cette écriture a été générée par un rattachement à l’exercice suivant : elle ne se modifie, ne se supprime '
        .'et ne s’annule pas ici. Pour la défaire, retirez le rattachement depuis Exercices > Écritures de rattachement.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly VerrouLotsCanoniques $verrouLots,
    ) {}

    /**
     * Garde du verrou (spec § 7) pour un geste qui écrit sur UNE ligne : refuse, par le message
     * unique MOTIF_PIECE_GELEE, si cette ligne porte un rattachement, et par MOTIF_ECRITURE_DE_RATTACHEMENT
     * si la ligne appartient à une écriture de rattachement (dotation ou extourne générée). Les gestes
     * qui réécrivent la pièce entière appellent verrouillerPieceNonGelee(). Sites : le reclassement
     * (Task 7) ; la ventilation, affecterLigne() et supprimerAffectations() (Task 8c) ; la
     * synchronisation HelloAsso (Task 9).
     *
     * À appeler DANS la transaction de l'appelant, APRÈS ses verrous d'exercice : les verrous
     * pris ici tiennent jusqu'à son commit, et c'est ce qui rend la lecture vraie au moment d'écrire.
     * Hors transaction, ils se relâchent aussitôt et la garde n'exclut plus rien.
     *
     * Ordre des verrous : la ligne, puis les rattachements en lecture verrouillante directe. C'est
     * une sous-suite de celui de rattacher() et de retirer() (association, N, N+1, pièce, ligne,
     * rattachements) : pas de cycle possible avec eux. Le verrou de ligne les exclut : un
     * rattachement validé pendant l'attente est lu au déverrouillage, et un rattachement concurrent
     * attend la fin de l'appelant.
     *
     * Une ligne d'écriture de rattachement ne porte jamais de rattachement (elle n'est l'origine
     * d'aucun) : sans le premier refus ci-dessous, tout geste sur une ligne la laisserait passer. La
     * ligne de charge de la dotation d'une CCA, par exemple, est une ligne de pièce de type Dépense que
     * le reclassement accepte : reclassée, elle porterait la dotation sur un compte et l'extourne sur
     * l'autre, et le résultat de N et de N+1 serait faux. Cette lecture ne verrouille pas, et n'inverse
     * donc pas l'ordre ligne / pièce : `provision_id` d'une écriture vivante ne change jamais. Il est
     * posé à la création de l'écriture, et ne repasse à nul que par la suppression du rattachement
     * (clé étrangère en nullOnDelete), qui suit la suppression physique de ses écritures
     * (ProvisionPDService::supprimer()) : une écriture vivante ne perd donc pas son marqueur, et une
     * pièce ordinaire n'en reçoit jamais. Tous scopes levés, comme le reste de la garde : une pièce
     * supprimée logiquement ou hors du contexte d'association garde son marqueur.
     *
     * REFUSE PAR DÉFAUT : la décision ne dépend que de l'identifiant de l'instance reçue, jamais
     * d'une relecture de la ligne. Une ligne supprimée logiquement, ou lue hors du contexte
     * d'association, est introuvable par une lecture ordinaire (suppression logique, scope tenant
     * fail-closed) : lire la ligne sous verrou et « ne rien trouver » ferait passer la garde, alors
     * que la clé étrangère du rattachement, elle, ignore tout scope. Les deux lectures lèvent donc
     * tous les scopes. Le verrou de ligne ne décide de rien : il exclut, et s'il ne trouvait rien,
     * la lecture des rattachements décide quand même.
     *
     * @throws RuntimeException MOTIF_PIECE_GELEE si la ligne porte un rattachement ;
     *                          MOTIF_ECRITURE_DE_RATTACHEMENT si sa pièce est une écriture de rattachement
     */
    public function assertLigneNonGelee(TransactionLigne $ligne): void
    {
        // Avant tout verrou : une écriture de rattachement n'est l'origine d'aucun rattachement, il n'y a
        // rien d'autre à lire ni à exclure. Voir la justification de la lecture sans verrou, plus haut.
        if (Transaction::withoutGlobalScopes()->whereKey((int) $ligne->transaction_id)->value('provision_id') !== null) {
            throw new RuntimeException(self::MOTIF_ECRITURE_DE_RATTACHEMENT);
        }

        // Tous scopes levés (suppression logique ET scope tenant) : cette lecture n'en tire aucune
        // donnée, elle pose le verrou. Un verrou qui ne s'appliquerait pas hors contexte
        // d'association laisserait rattacher() s'intercaler.
        TransactionLigne::withoutGlobalScopes()->whereKey((int) $ligne->id)->lockForUpdate()->value('id');

        if ($ligne->estRattacheeSousVerrou()) {
            throw new RuntimeException(self::MOTIF_PIECE_GELEE);
        }
    }

    /**
     * Garde du verrou (spec § 7) pour un geste qui RÉÉCRIT la pièce entière : refuse, par le message
     * unique MOTIF_PIECE_GELEE, si l'une de ses lignes, supprimée logiquement ou non, porte un
     * rattachement (une ligne rattachée gèle sa transaction entière), et, par MOTIF_ECRITURE_DE_RATTACHEMENT,
     * si la pièce est elle-même une écriture de rattachement. Rend la pièce verrouillée, relue sous
     * verrou : l'appelant poursuit avec elle, et non avec l'instance qu'il avait reçue.
     *
     * Sites : l'édition, la suppression et l'annulation de TransactionService (Task 8a). L'annulation par
     * extourne a sa variante datée, verrouillerPieceExtournable() (D13 : une annulation datée après N reste
     * permise) : cette garde-ci refuse sans condition de date.
     *
     * RÈGLE DES SITES : l'appeler juste après le verrou d'exercice, SANS avoir verrouillé la pièce avant.
     * Elle prend elle-même, dans l'ordre canonique, tout ce qu'il faut : l'appelant qui verrouillerait la
     * pièce avant elle inverserait l'ordre des lots (voir verrouillerPieceEtRattachements()). À appeler DANS
     * la transaction de l'appelant (voir assertLigneNonGelee()) : les verrous tiennent jusqu'à son commit.
     *
     * La pièce rendue est lue tous scopes levés, comme tout le reste : supprimée logiquement ou d'une autre
     * association, elle est rendue quand même. Un appelant qui exige une pièce vivante de l'association
     * (update()) le contrôle lui-même.
     *
     * @throws RuntimeException MOTIF_PIECE_GELEE si l'une des lignes de la pièce porte un rattachement ;
     *                          MOTIF_ECRITURE_DE_RATTACHEMENT si la pièce est une écriture de rattachement
     * @throws ModelNotFoundException la pièce n'existe plus en base (supprimée physiquement)
     */
    public function verrouillerPieceNonGelee(Transaction $piece): Transaction
    {
        ['piece' => $verrouillee, 'exercices' => $exercices] = $this->verrouillerPieceEtRattachements($piece);

        if ($exercices !== []) {
            throw new RuntimeException(self::MOTIF_PIECE_GELEE);
        }

        return $verrouillee;
    }

    /**
     * Garde du verrou (spec § 7, D13) pour l'annulation PAR EXTOURNE d'une pièce : variante datée de
     * verrouillerPieceNonGelee(). Même préfixe de verrous, même pièce rendue, même refus d'une écriture de
     * rattachement (MOTIF_ECRITURE_DE_RATTACHEMENT) ; mais une pièce rattachée n'est refusée que si
     * l'annulation est datée AU PLUS TARD le dernier jour de N, l'exercice du rattachement, antérieure à
     * N comprise. Datée dans N, elle retirerait deux fois le même montant de N (la dotation du rattachement
     * le retire déjà). Datée après N, elle est juste : l'extourne du rattachement, datée du premier jour de
     * N+1, et l'annulation s'y compensent, et c'est le refus qui rendrait la pièce inannulable pour toujours
     * une fois N clôturé. La date qui compte est celle que prendra la pièce miroir, `$dateAnnulation`.
     *
     * Une pièce portant des rattachements d'exercices différents est jugée sur le plus récent, N le plus
     * grand : refuser dès que l'annulation tombe dans l'un d'eux revient à la comparer à la fin du dernier.
     *
     * À appeler DANS la transaction de l'extourne, juste après le verrou de l'exercice de l'annulation et
     * AVANT la numérotation du miroir : rattacher() tient la pièce puis numérote, une extourne qui
     * numéroterait d'abord s'inter-bloquerait avec lui. Voir verrouillerPieceNonGelee() pour la règle des
     * sites, et verrouillerPieceEtRattachements() pour l'ordre des verrous.
     *
     * @param  CarbonInterface  $dateAnnulation  la date comptable de la pièce miroir (ExtournePayload::$date)
     * @param  bool  $dateImposee  la date n'est pas choisie par l'utilisateur mais imposée par l'appelant
     *                             (ExtournePayload::$dateImposee) : le refus ne propose alors pas de « dater
     *                             l'annulation » après N, sortie qu'il n'a pas, mais d'attendre ce jour-là
     *
     * @throws RuntimeException MOTIF_ECRITURE_DE_RATTACHEMENT si la pièce est une écriture de rattachement ;
     *                          le motif de motifExtournePrecoce() si elle porte un rattachement dont
     *                          l'exercice finit à la date de l'annulation ou après elle
     * @throws ModelNotFoundException la pièce n'existe plus en base (supprimée physiquement)
     */
    public function verrouillerPieceExtournable(Transaction $origine, CarbonInterface $dateAnnulation, bool $dateImposee = false): Transaction
    {
        ['piece' => $verrouillee, 'exercices' => $exercices] = $this->verrouillerPieceEtRattachements($origine);

        if ($exercices === []) {
            return $verrouillee;
        }

        $exercice = max($exercices);
        $finDeN = $this->exerciceService->dateRange($exercice)['end'];

        // Chaînes Y-m-d : la date de l'annulation peut porter une heure, la fin de N est minuit.
        if ($dateAnnulation->toDateString() <= $finDeN->toDateString()) {
            throw new RuntimeException($this->motifExtournePrecoce($exercice, $finDeN, $dateImposee));
        }

        return $verrouillee;
    }

    /**
     * Le refus d'une annulation par extourne datée au plus tard le dernier jour de N : il dit pourquoi
     * (le même montant serait retiré deux fois de l'exercice N), et la sortie — qui dépend de qui choisit
     * la date. Sans jargon : « annulation », non « extourne ».
     *
     * Date choisie (la fenêtre d'annulation) : dater l'annulation après N, ou retirer d'abord le
     * rattachement. Date imposée (l'annulation d'une facture, qui a lieu aujourd'hui) : « dater » n'y est
     * pas possible, la sortie est d'attendre le premier jour de N+1, ou de retirer d'abord le rattachement.
     */
    private function motifExtournePrecoce(int $exercice, CarbonInterface $finDeN, bool $dateImposee): string
    {
        $retrait = 'retirez d’abord le rattachement depuis Exercices > Écritures de rattachement';

        if ($dateImposee) {
            $debutDeNSuivant = $this->exerciceService->dateRange($exercice + 1)['start']->format('d/m/Y');

            return 'Cette pièce porte un rattachement à l’exercice suivant : l’annuler aujourd’hui retirerait deux fois le même montant de l’exercice '
                .$this->exerciceService->label($exercice).'. '
                .ucfirst($retrait).', ou attendez le '.$debutDeNSuivant.' pour l’annuler.';
        }

        $fin = $finDeN->format('d/m/Y');

        return 'Cette pièce porte un rattachement à l’exercice suivant : l’annuler avec une date au plus tard le '.$fin
            .' retirerait deux fois le même montant de l’exercice '.$this->exerciceService->label($exercice).'. '
            .'Datez l’annulation après le '.$fin.', ou '.$retrait.'.';
    }

    /**
     * Le préfixe de verrous commun aux gardes de la pièce : verrouille, lit et rend ce qu'il faut pour
     * en décider, sans décider lui-même, hormis le refus d'une écriture de rattachement, qui ne dépend
     * d'aucune date. Rend la pièce verrouillée, et les exercices (N) de tous les rattachements de ses
     * lignes, dans l'ordre où la base les rend : la liste vide dit que la pièce ne porte aucun rattachement.
     *
     * Ordre des verrous : les lots 401/411 de la pièce (VerrouLotsCanoniques), la pièce, ses lignes dans
     * l'ordre de leur identifiant, puis UNE lecture verrouillante directe des rattachements de ces lignes.
     * C'est l'ordre de TransactionService::update() depuis l'origine (le lot, puis la pièce, comme
     * PosteTiersReglementService::regler()), prolongé : la garde verrouille TOUTES les lignes, 401/411
     * comprises, et ne peut le faire qu'après la pièce. Elle commence donc par les lots, faute de quoi
     * un règlement tiers, qui tient le lot et attend la pièce, s'inter-bloquerait avec un geste qui tient
     * la pièce et attend le lot. rattacher() et retirer() prennent la pièce, puis leur seule ligne (de
     * classe 6 ou 7, jamais un lot), puis les rattachements : même ordre, pas de cycle possible avec
     * eux, qui attendent en outre la fin de l'appelant dès la pièce.
     *
     * REFUSE PAR DÉFAUT : la décision ne dépend que de l'identifiant de l'instance reçue. La pièce, ses
     * lignes (suppression logique comprise) et les rattachements sont lus tous scopes levés : une pièce
     * supprimée logiquement ou hors du contexte d'association porte encore ses rattachements, et la
     * clé étrangère, elle, ignore tout scope. Seuls les lots se lisent sous le scope tenant (leur ordre
     * exclut, il ne décide de rien). Les identifiants des lignes viennent de la lecture verrouillante des
     * lignes : elle lit le dernier état validé, et non le cliché de la transaction, qui peut précéder
     * une ligne recréée. `provision_id` se lit sur la pièce verrouillée, pas sur l'instance reçue. Tant
     * que l'appelant tient la pièce, aucun rattachement de ses lignes ne peut être créé ni retiré :
     * rattacher() et retirer() l'attendent.
     *
     * @return array{piece: Transaction, exercices: list<int>}
     *
     * @throws RuntimeException MOTIF_ECRITURE_DE_RATTACHEMENT si la pièce est une écriture de rattachement
     * @throws ModelNotFoundException la pièce n'existe plus en base (supprimée physiquement)
     */
    private function verrouillerPieceEtRattachements(Transaction $piece): array
    {
        $pieceId = (int) $piece->id;

        // Le préfixe canonique : les lots 401/411, AVANT la pièce.
        $this->verrouLots->verrouillerLotsDeLaPiece($pieceId);

        // Tous scopes levés, comme assertLigneNonGelee() : une pièce supprimée logiquement porte
        // encore ses rattachements, et le verrou doit l'exclure de rattacher() et de retirer().
        $verrouillee = Transaction::withoutGlobalScopes()->whereKey($pieceId)->lockForUpdate()->first();

        if ($verrouillee === null) {
            throw (new ModelNotFoundException)->setModel(Transaction::class, [$pieceId]);
        }

        // Une écriture de rattachement ne se réécrit pas ici, et ne s'annule pas davantage, quelle que
        // soit la date : elle se défait avec son rattachement. Lu sur la pièce verrouillée : refuser sur
        // l'instance reçue laisserait passer l'appelant dont la copie est périmée. Avant de verrouiller
        // les lignes : une écriture n'est l'origine d'aucun rattachement, il n'y a rien d'autre à lire.
        if ($verrouillee->provision_id !== null) {
            throw new RuntimeException(self::MOTIF_ECRITURE_DE_RATTACHEMENT);
        }

        // Les lignes, supprimées logiquement comprises, dans l'ordre de leur identifiant : deux
        // appelants qui verrouillent les mêmes lignes ne s'attendent pas l'un l'autre en sens inverse.
        $ligneIds = TransactionLigne::withoutGlobalScopes()
            ->where('transaction_id', $pieceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        // UNE lecture verrouillante directe, comme estRattacheeSousVerrou() : ni EXISTS, ni une
        // lecture par ligne. Elle lit TOUS les rattachements de la pièce, et non le premier : le verrou
        // de chacun exclut son retrait, et l'exercice de chacun décide de la garde datée. Une pièce
        // sans ligne ne porte aucun rattachement : rien à lire.
        $exercices = $ligneIds === []
            ? []
            : Provision::withoutGlobalScopes()
                ->whereIn('transaction_ligne_id', $ligneIds)
                ->lockForUpdate()
                ->pluck('exercice')
                ->map(fn (mixed $exercice): int => (int) $exercice)
                ->all();

        return ['piece' => $verrouillee, 'exercices' => $exercices];
    }
}
