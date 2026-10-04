<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\JournalComptable;
use App\Models\Extourne;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Rattachement d'un produit ou d'une charge à l'exercice suivant (CCA/PCA).
 *
 * Toutes les gardes d'éligibilité de la LIGNE vivent dans motifDeRefus() : le service
 * s'y réfère, et l'écran (Task 11) comme la requête de détection (Task 10) devront s'y
 * référer aussi. La détection reproduira ces gardes en SQL, plus celle qui lui est
 * propre (une opération postérieure à l'exercice) : un test devra vérifier qu'elle ne
 * propose rien que motifDeRefus() refuse. Ce que le service accepte n'est pas pour
 * autant tout ce que la détection propose.
 *
 * L'ouverture de la PÉRIODE se contrôle à part : sous verrou dans rattacher() (exercices
 * N et N+1), et par période à l'écran (Task 11). Une ligne éligible dont l'exercice, ou
 * le suivant, est clôturé est donc refusée par rattacher() sans que motifDeRefus() l'ait
 * écartée.
 *
 * Référence : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md
 * (D4, D9, D13, D14 pour les gardes ; § 5 pour les verrous d'exercice ; § 7 pour
 * la résolution de la ligne).
 */
final class RattachementExerciceService
{
    /** Formulation unique du refus, qui sera partagée avec la détection (Task 10). */
    public const MOTIF_AFFECTATIONS = 'Cette ligne est ventilée sur des opérations : son rattachement n’est pas encore pris en charge.';

    private const MOTIF_DEJA_RATTACHEE = 'Cette ligne est déjà rattachée à l’exercice suivant : retirez son rattachement avant de le refaire.';

    private const MOTIF_ANNULATION = 'Cette pièce annule une autre pièce : elle ne peut pas être rattachée à l’exercice suivant.';

    private const MOTIF_ANNULEE_AU_PLUS_TARD_EN_N = 'Cette pièce a été annulée avant la fin de son exercice : la rattacher retirerait deux fois le même montant. '
        .'S’il existe une pièce qui la remplace, rattachez celle-là.';

    private const MOTIF_ANNULEE_DATE_INTROUVABLE = 'Cette pièce a été annulée, mais la date de son annulation est introuvable : par prudence, elle ne peut pas être rattachée.';

    private const MOTIF_LIGNE_DISPARUE = 'Cette ligne n’existe plus : sa pièce a été modifiée entre-temps. Rechargez la page.';

    private const MOTIF_PIECE_SUPPRIMEE_ENTRE_TEMPS = 'La pièce de cette ligne a été supprimée entre-temps. Rechargez la page.';

    private const MOTIF_PIECE_REDATEE = 'La date de la pièce a changé entre-temps : elle n’appartient plus au même exercice. Rechargez la page.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly ProvisionPDService $provisionPD,
    ) {}

    /**
     * Rend le motif pour lequel cette ligne n'est pas rattachable, ou null.
     *
     * Les messages sont destinés à l'utilisateur : ils expliquent et, quand il y en a une,
     * indiquent la sortie. Les gardes se suivent comme les questions qu'on pose à la ligne :
     * sa pièce existe-t-elle ; est-ce déjà une écriture de rattachement ; est-ce une charge
     * ou un produit ; d'où vient-elle (journal, annulation) ; puis son sens, sa ventilation,
     * son état. Seul l'ordre « écriture de rattachement avant journal des opérations
     * diverses » est contractuel (AC-11) : quand deux gardes sont vraies ensemble, seul le
     * motif affiché change.
     *
     * Jugement sur l'état lu : rattacher() le refait sous verrou avant d'écrire.
     */
    public function motifDeRefus(TransactionLigne $ligne): ?string
    {
        return $this->juger($ligne, $ligne->transaction, false);
    }

    /**
     * Juge la ligne d'après la pièce donnée : la liste ordonnée des gardes d'éligibilité, un
     * motif de refus ou null. Appelée sur l'état lu (motifDeRefus) comme sur l'état verrouillé
     * (rattacher).
     *
     * @param  bool  $sousVerrou  vrai dans la transaction de rattacher() : les affectations et
     *                            « déjà rattachée » se lisent alors par des lectures verrouillantes
     */
    private function juger(TransactionLigne $ligne, ?Transaction $transaction, bool $sousVerrou): ?string
    {
        if ($transaction === null) {
            return 'La pièce de cette ligne a été supprimée.';
        }

        // AVANT D14 : les écritures de rattachement sont elles-mêmes en journal OD,
        // et c'est ce motif-ci qu'elles doivent recevoir (AC-11).
        if ($transaction->provision_id !== null) {
            return 'Cette ligne appartient à une écriture de rattachement : elle ne peut pas être rattachée à son tour.';
        }

        // Une contrepartie 411/401/512 d'une pièce annulée ou d'une écriture d'inventaire
        // reçoit ce motif-ci, qui la décrit, plutôt que celui de la pièce.
        $compte = $ligne->compte;

        if ($compte === null || ! in_array((int) $compte->classe, [6, 7], true)) {
            return 'Seule une ligne de charge ou de produit peut être rattachée.';
        }

        // D14 : une écriture d'inventaire ne se rattache pas.
        if ($transaction->journal === JournalComptable::Od) {
            return 'Cette ligne appartient à une écriture d’inventaire (journal des opérations diverses) : '
                .'seule une ligne d’une pièce d’achat, de vente ou de banque peut être rattachée à l’exercice suivant.';
        }

        // D13 : annulation, ou pièce annulée.
        $motifAnnulation = $this->motifAnnulation($transaction);

        if ($motifAnnulation !== null) {
            return $motifAnnulation;
        }

        $debit = (float) $ligne->debit;
        $credit = (float) $ligne->credit;

        // D9 : une classe 6 se rattache au débit, une classe 7 au crédit. Un
        // contra-compte (709 au débit, 609 au crédit) porte un montant de sens
        // inverse : le schéma fixe l'amplifierait au lieu de le neutraliser.
        $sensAttendu = (int) $compte->classe === 7 ? $credit > 0.0 : $debit > 0.0;

        if (! $sensAttendu) {
            return 'Cette ligne est de sens inverse ('.$compte->numero_pcg.' au '
                .((int) $compte->classe === 7 ? 'débit' : 'crédit')
                .') : son rattachement accentuerait son effet au lieu de le reporter sur l’exercice suivant.';
        }

        // D4 : la ventilation vit dans les affectations, la ligne n'a pas
        // d'opération propre à hériter. Sous verrou, la lecture est verrouillante : c'est la
        // seule donnée de l'éligibilité que ni le verrou de la pièce ni celui de la ligne
        // ne protègent (affecterLigne() n'écrit que dans transaction_ligne_affectations), et
        // une affectation validée pendant l'attente du verrou serait invisible du cliché. Lecture
        // verrouillante directe, comme estRattacheeSousVerrou() : pas de FOR UPDATE dans un EXISTS.
        if ($sousVerrou ? $ligne->affectations()->lockForUpdate()->value('id') !== null : $ligne->affectations()->exists()) {
            return self::MOTIF_AFFECTATIONS;
        }

        if ($sousVerrou ? $ligne->estRattacheeSousVerrou() : $ligne->estRattachee()) {
            return self::MOTIF_DEJA_RATTACHEE;
        }

        return null;
    }

    /**
     * D13 : une annulation ne se rattache jamais ; une pièce annulée se juge sur la date
     * COMPTABLE de son annulation, c'est-à-dire celle de la pièce miroir. extournee_at n'est
     * que l'horodatage du geste (now()) : il dit qu'une annulation existe, pas où elle tombe.
     * Annulée au plus tard le dernier jour de N, rattacher l'origine retirerait deux fois le
     * même montant ; annulée après, rattacher est juste, et c'est le refus qui laisserait N faux.
     */
    private function motifAnnulation(Transaction $transaction): ?string
    {
        if ($transaction->type_ecriture === 'extourne'
            || Extourne::where('transaction_extourne_id', (int) $transaction->id)->exists()) {
            return self::MOTIF_ANNULATION;
        }

        if ($transaction->extournee_at === null) {
            return null;
        }

        $miroir = $transaction->extourneeVers?->extourne;

        // La date est introuvable : on ne devine pas, on refuse.
        if ($miroir === null) {
            return self::MOTIF_ANNULEE_DATE_INTROUVABLE;
        }

        $finDeN = $this->exerciceService->dateRange($this->exerciceService->anneeForDate($transaction->date))['end'];

        if ($miroir->date->toDateString() <= $finDeN->toDateString()) {
            return self::MOTIF_ANNULEE_AU_PLUS_TARD_EN_N;
        }

        return null;
    }

    /**
     * Variante par identifiant : la ligne est résolue dans l'association courante.
     *
     * @throws RuntimeException voir rattacher() ; « Cette ligne n’existe pas. » pour un
     *                          identifiant inconnu ou d'une autre association
     */
    public function rattacherParId(int $ligneId, ?string $notes, User $auteur): Provision
    {
        // Résolution explicite dans le tenant (§ 7) : un identifiant forgé d'une
        // autre association est traité comme inexistant, pas servi.
        // TransactionLigneTenantScope le filtre aussi : deux filets, volontairement.
        $ligne = TransactionLigne::whereHas(
            'transaction',
            fn (Builder $q) => $q->where('association_id', (int) TenantContext::currentId())
        )->find($ligneId);

        if ($ligne === null) {
            throw new RuntimeException('Cette ligne n’existe pas.');
        }

        return $this->rattacher($ligne, $notes, $auteur);
    }

    /**
     * Rattache la ligne à l'exercice suivant : crée le rattachement et ses deux écritures
     * (dotation au dernier jour de N, extourne au premier jour de N+1).
     *
     * Appel authentifié requis. Les écritures prennent leur auteur (`saisi_par`) sur
     * Auth::id() (EcritureGenerator::pourRattachement), pas sur $auteur, qui n'est
     * enregistré que sur le rattachement : hors session, elles seraient créées sans auteur.
     *
     * @throws RuntimeException le motif de refus de motifDeRefus() (rejugé sous verrou) ; une
     *                          ligne ou une pièce supprimée, ou redatée dans un autre exercice,
     *                          entre-temps ; ExerciceCloturedException (une RuntimeException)
     *                          si l'exercice N ou N+1 est clôturé
     */
    public function rattacher(TransactionLigne $ligne, ?string $notes, User $auteur): Provision
    {
        $motif = $this->motifDeRefus($ligne);

        if ($motif !== null) {
            throw new RuntimeException($motif);
        }

        $transaction = $ligne->transaction;
        $exercice = $this->exerciceService->anneeForDate($transaction->date);
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        // 3 tentatives : un inter-blocage est rejoué (la synchronisation HelloAsso modifie la ligne
        // avant la pièce ; extourner() numérote avant d'écrire la pièce). Sans danger : tout est
        // relu sous verrou à chaque tentative.
        [$provision, $cas] = DB::transaction(function () use ($ligne, $exercice, $notes, $auteur): array {
            // Ordre canonique de cloturer() et du § 5 : association, exercice source,
            // exercice cible, puis écritures. La dotation vit dans N, l'extourne dans
            // N+1 : les deux doivent être ouverts. Un exercice absent n'est pas clôturé
            // (l'extourne est générée même si N+1 n'existe pas encore).
            $this->exerciceService->assertOuvertPourEcriture($exercice);
            $this->exerciceService->assertOuvertVerrouille($exercice + 1);

            ['piece' => $piece, 'ligne' => $ligneVerrouillee] = $this->verrouillerEtRelire($ligne, $exercice);

            // L'éligibilité est jugée sur l'état verrouillé, pas sur celui lu avant : ce que
            // motifDeRefus() a conclu hors transaction a pu être dépassé entre-temps. Les
            // affectations et « déjà rattachée » se lisent par des lectures verrouillantes, qui
            // voient le dernier état validé quel que soit le cliché de la transaction.
            $motif = $this->juger($ligneVerrouillee, $piece, true);

            if ($motif !== null) {
                throw new RuntimeException($motif);
            }

            $montant = round(max((float) $ligneVerrouillee->debit, (float) $ligneVerrouillee->credit), 2);

            // `libelle` reste nul : en mode adossé le libellé est celui de la ligne
            // d'origine, lu par Provision::libelleEffectif(), comme le compte,
            // l'opération et la séance (D3). Le recopier créerait une seconde source.
            $provision = Provision::create([
                'association_id' => (int) TenantContext::currentId(),
                'exercice' => $exercice,
                'transaction_ligne_id' => (int) $ligneVerrouillee->id,
                'montant' => $montant,
                'notes' => $notes,
                'saisi_par' => (int) $auteur->id,
            ]);

            // L'écriture porte la ligne jugée sous verrou : sans cela, generer() relirait
            // $provision->ligne hors verrou, dans un cliché qui peut précéder le verrou, et
            // écrirait un libellé, un compte ou une opération périmés.
            $provision->setRelation('ligne', $ligneVerrouillee);

            // Calculé avant le commit : un compte sans cas fait échouer la transaction entière.
            $cas = $provision->cas()->value;

            $this->provisionPD->generer($provision);

            return [$provision, $cas];
        }, 3);

        // Après le commit, rien ne doit plus pouvoir lever : un rattachement validé ne doit pas
        // remonter comme une erreur.
        Log::info('[Rattachement] écriture de rattachement créée', [
            'provision_id' => (int) $provision->id,
            'transaction_ligne_id' => (int) $ligne->id,
            'cas' => $cas,
            'exercice' => $exercice,
            'montant' => round((float) $provision->montant, 2),
        ]);

        return $provision;
    }

    /**
     * Verrouille la pièce puis la ligne, dans cet ordre, et rend leur état verrouillé : celui
     * sur lequel l'éligibilité se rejuge et le montant se calcule. À appeler dans une
     * transaction, après les verrous d'exercice ; ils tiennent jusqu'au commit.
     *
     * L'ordre est celui de TransactionService::update() (exercice, pièce, puis suppression des
     * lignes). Parmi les écritures qui touchent la pièce ou la ligne, seul rattacher() prend le
     * verrou d'association : les autres (édition, annulation, synchronisation HelloAsso) ne
     * s'excluent que par ces deux verrous.
     *
     * @param  int  $exercice  l'exercice N dont les verrous viennent d'être pris : la date de la
     *                         pièce relue sous verrou ne doit pas l'avoir quitté
     * @return array{piece: Transaction, ligne: TransactionLigne}
     *
     * @throws RuntimeException pièce supprimée, ligne disparue ou passée à une autre pièce,
     *                          pièce redatée dans un autre exercice : refus lisible, pas une
     *                          erreur technique
     */
    private function verrouillerEtRelire(TransactionLigne $ligne, int $exercice): array
    {
        $piece = Transaction::query()->whereKey((int) $ligne->transaction_id)->lockForUpdate()->first();
        $ligneVerrouillee = TransactionLigne::query()->whereKey((int) $ligne->id)->lockForUpdate()->first();

        if ($piece === null) {
            throw new RuntimeException(self::MOTIF_PIECE_SUPPRIMEE_ENTRE_TEMPS);
        }

        // TransactionService::update() recrée toutes les lignes d'une pièce : l'identifiant lu
        // avant peut ne plus désigner qu'une ligne disparue.
        if ($ligneVerrouillee === null
            || (int) $ligneVerrouillee->transaction_id !== (int) $piece->id) {
            throw new RuntimeException(self::MOTIF_LIGNE_DISPARUE);
        }

        // Les exercices verrouillés plus haut sont ceux de l'ancienne date de la pièce.
        if ($this->exerciceService->anneeForDate($piece->date) !== $exercice) {
            throw new RuntimeException(self::MOTIF_PIECE_REDATEE);
        }

        return ['piece' => $piece, 'ligne' => $ligneVerrouillee];
    }
}
