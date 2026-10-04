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
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
 * L'ouverture de la PÉRIODE se contrôle à part : sous verrou dans rattacher() et
 * retirer() (exercices N et N+1), et par période à l'écran (Task 11). Une ligne éligible
 * dont l'exercice, ou le suivant, est clôturé est donc refusée par rattacher() sans que
 * motifDeRefus() l'ait écartée.
 *
 * Le VERROU d'une ligne rattachée (§ 7) vit ici aussi : les gestes qui écrivent sur une pièce
 * dont une ligne est rattachée s'y réfèrent par assertLigneNonGelee() (une ligne) et
 * assertPieceNonGelee() (la pièce entière), plutôt que de relire eux-mêmes les rattachements.
 * Elles refusent par défaut et partagent le message MOTIF_PIECE_GELEE.
 *
 * Référence : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md
 * (D4, D9, D13, D14 pour les gardes ; § 5 pour les verrous d'exercice ; § 7 pour
 * la résolution de la ligne et le verrou).
 */
final class RattachementExerciceService
{
    /** Formulation unique du refus, qui sera partagée avec la détection (Task 10). */
    public const MOTIF_AFFECTATIONS = 'Cette ligne est ventilée sur des opérations : son rattachement n’est pas encore pris en charge.';

    /**
     * Le message unique du verrou (spec § 7), destiné à TOUS les sites qui refusent d'écrire sur
     * une pièce dont une ligne est rattachée : reclassement, retrait de ligne, édition, suppression,
     * annulation, extourne, ventilation, synchronisation HelloAsso. Posé aujourd'hui sur le
     * reclassement, le retrait de ligne, l'édition, la suppression et l'annulation ; les autres
     * sites suivent (Tasks 8b, 8c et 9). Il nomme le constat, l'écran où se défait le rattachement
     * et la sortie : le retirer, corriger, le repasser. Un site qui en formulerait un autre rendrait
     * le refus illisible d'un écran à l'autre.
     */
    public const MOTIF_PIECE_GELEE = 'Cette pièce porte un rattachement à l’exercice suivant : pour la modifier, retirez d’abord le rattachement '
        .'depuis Exercices > Écritures de rattachement, corrigez, puis repassez-le.';

    /**
     * Le refus des gestes qui réécrivent une pièce (édition, suppression, annulation) quand cette
     * pièce EST une écriture de rattachement, dotation ou extourne générée. Distinct de
     * MOTIF_PIECE_GELEE : il n'y a rien à retirer puis repasser sur cette pièce, c'est le
     * rattachement qui la porte, et lui seul la défait avec sa jumelle. La supprimer ou l'annuler ici
     * romprait la paire dotation / extourne : le résultat de N ou de N+1 serait faux, et le retrait ne
     * retrouverait plus l'écriture supprimée logiquement (ProvisionPDService::supprimer() ne la voit pas,
     * et `provision_id` passerait à nul avec le rattachement). La modifier ferait diverger ses montants
     * de ceux du rattachement.
     */
    public const MOTIF_ECRITURE_DE_RATTACHEMENT = 'Cette écriture a été générée par un rattachement à l’exercice suivant : elle ne se modifie, ne se supprime '
        .'et ne s’annule pas ici. Pour la défaire, retirez le rattachement depuis Exercices > Écritures de rattachement.';

    private const MOTIF_DEJA_RATTACHEE = 'Cette ligne est déjà rattachée à l’exercice suivant : retirez son rattachement avant de le refaire.';

    private const MOTIF_ANNULATION = 'Cette pièce annule une autre pièce : elle ne peut pas être rattachée à l’exercice suivant.';

    private const MOTIF_ANNULEE_AU_PLUS_TARD_EN_N = 'Cette pièce a été annulée avant la fin de son exercice : la rattacher retirerait deux fois le même montant. '
        .'S’il existe une pièce qui la remplace, rattachez celle-là.';

    private const MOTIF_ANNULEE_DATE_INTROUVABLE = 'Cette pièce a été annulée, mais la date de son annulation est introuvable : par prudence, elle ne peut pas être rattachée.';

    private const MOTIF_LIGNE_DISPARUE = 'Cette ligne n’existe plus : sa pièce a été modifiée entre-temps. Rechargez la page.';

    private const MOTIF_PIECE_SUPPRIMEE_ENTRE_TEMPS = 'La pièce de cette ligne a été supprimée entre-temps. Rechargez la page.';

    private const MOTIF_PIECE_REDATEE = 'La date de la pièce a changé entre-temps : elle n’appartient plus au même exercice. Rechargez la page.';

    private const MOTIF_RATTACHEMENT_INTROUVABLE = 'Ce rattachement n’existe plus : il a peut-être déjà été retiré. Rechargez la page.';

    private const MOTIF_RETRAIT_SAISIE_LIBRE = 'Ce rattachement est une saisie libre : son retrait n’est pas encore pris en charge.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly ProvisionPDService $provisionPD,
        private readonly VerrouLotsCanoniques $verrouLots,
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
     * Variante par identifiant, symétrique de rattacherParId() : le rattachement est résolu
     * dans l'association courante.
     *
     * @throws RuntimeException voir retirer() ; « Ce rattachement n’existe plus » pour un
     *                          identifiant inconnu, déjà retiré ou d'une autre association
     */
    public function retirerParId(int $provisionId): void
    {
        // Résolution sous le scope tenant, volontairement : un identifiant forgé d'une autre
        // association est traité comme inexistant, avant même d'ouvrir une transaction.
        // retirer() relit le rattachement sous verrou et sous le même scope : deux filets.
        $provision = Provision::find($provisionId);

        if ($provision === null) {
            throw new RuntimeException(self::MOTIF_RATTACHEMENT_INTROUVABLE);
        }

        $this->retirer($provision);
    }

    /**
     * Retire le rattachement : supprime ses deux écritures (dotation et extourne), puis
     * supprime PHYSIQUEMENT sa ligne de `provisions`, ce qui libère la ligne d'origine. Le
     * modèle n'a pas de suppression logique : une ligne de `provisions` existe si et seulement
     * si la ligne d'origine est verrouillée (spec § 6.4). Il ne reste aucune trace en base ;
     * la trace est le journal applicatif.
     *
     * Ne retire que les rattachements adossés à une ligne d'origine (CCA, PCA) : la saisie
     * libre (FNP, PAR) est du lot 2.
     *
     * Les verrous suivent l'ordre de rattacher() : association et exercice N (le même appel
     * qu'à la création), exercice N+1, pièce, ligne, puis le rattachement EN DERNIER. C'est
     * l'ordre dans lequel rattacher() lit `provisions` (après la pièce et la ligne), et celui
     * que suivront les gardes d'écriture des Tasks 7 à 9 : un retrait qui verrouillerait le
     * rattachement d'abord s'inter-bloquerait avec eux. L'exercice est celui du
     * rattachement (`$provision->exercice`), qui est celui de ses écritures, et non la date
     * de la pièce : la pièce a pu être supprimée ou redatée depuis.
     *
     * retirer() ne rejuge PAS la pièce ni la ligne comme le fait rattacher() (verrouillerEtRelire() :
     * pièce supprimée, ligne disparue, pièce redatée). Ces refus-là protègent une création ; le
     * retrait est la sortie, et c'est précisément le cas d'une origine supprimée qu'il doit
     * nettoyer. Il les verrouille donc supprimées logiquement comprises : sa seule raison de
     * les verrouiller est de s'exclure avec ce qui les écrit.
     *
     * Appelable plusieurs fois sur la même instance : le rattachement est relu sous verrou, et
     * un rattachement déjà retiré (ici, ou par un autre utilisateur) est un refus lisible.
     *
     * @throws RuntimeException rattachement en saisie libre ; rattachement déjà retiré, ou d'une
     *                          autre association ; ExerciceCloturedException (une RuntimeException)
     *                          si l'exercice N ou N+1 est clôturé
     */
    public function retirer(Provision $provision): void
    {
        if (! $provision->estAdossee()) {
            throw new RuntimeException(self::MOTIF_RETRAIT_SAISIE_LIBRE);
        }

        // Ces trois colonnes ne changent jamais après la création : lues sur l'instance reçue,
        // elles désignent aussi ce que la transaction va verrouiller.
        $exercice = (int) $provision->exercice;
        $provisionId = (int) $provision->id;
        $ligneId = (int) $provision->transaction_ligne_id;

        // Lecture simple, hors transaction, comme rattacher() lit $ligne->transaction_id : elle
        // ne sert qu'à savoir QUELLE pièce verrouiller, et ne peut pas être périmée. Une ligne
        // ne change jamais de pièce : TransactionService::update() en recrée de nouvelles, sous
        // d'autres identifiants, et aucun chemin ne réécrit transaction_lignes.transaction_id. Tant
        // que le rattachement existe, la clé étrangère RESTRICT interdit en outre de supprimer
        // physiquement la ligne. Supprimée logiquement, elle reste lisible (withTrashed() lève le
        // seul filtre de suppression logique, pas le scope tenant). Absente : détruite par une
        // édition après un retrait, ou d'une autre association ; dans les deux cas le
        // rattachement est introuvable sous verrou, et rien n'est à verrouiller côté pièce.
        $pieceId = TransactionLigne::withTrashed()->whereKey($ligneId)->value('transaction_id');
        $pieceId = $pieceId !== null ? (int) $pieceId : null;

        // 3 tentatives, comme rattacher() : un inter-blocage est rejoué, tout est relu sous verrou.
        $montant = DB::transaction(function () use ($exercice, $provisionId, $ligneId, $pieceId): float {
            // Ordre canonique de rattacher() : association, exercice source, exercice cible.
            // La dotation vit dans N, l'extourne dans N+1 : les deux doivent être ouverts.
            $this->exerciceService->assertOuvertPourEcriture($exercice);
            $this->exerciceService->assertOuvertVerrouille($exercice + 1);

            $this->verrouillerPieceEtLigne($pieceId, $ligneId, inclureSupprimees: true);

            // Le rattachement en dernier. Sous le scope tenant, volontairement : une instance
            // d'une autre association n'est pas trouvée, au lieu d'être supprimée alors que ses
            // écritures, que supprimer() ne voit pas hors de son association, resteraient.
            // estRattacheeSousVerrou() lève au contraire tous les scopes : c'est une garde, que le
            // scope fail-closed rendrait passante. Ici on détruit : ne rien trouver est un refus.
            $verrouille = Provision::query()->whereKey($provisionId)->lockForUpdate()->first();

            if ($verrouille === null) {
                throw new RuntimeException(self::MOTIF_RATTACHEMENT_INTROUVABLE);
            }

            // Les écritures d'abord : transactions.provision_id étant en nullOnDelete, supprimer le
            // rattachement le premier laisserait les écritures orphelines, hors de portée de supprimer().
            $this->provisionPD->supprimer($verrouille);
            $verrouille->delete();

            return round((float) $verrouille->montant, 2);
        }, 3);

        // Après le commit, rien ne doit plus pouvoir lever : un retrait validé ne doit pas
        // remonter comme une erreur. Pas de cas ici : il se lit sur la ligne d'origine, qui a pu
        // être supprimée.
        Log::info('[Rattachement] écriture de rattachement retirée', [
            'provision_id' => $provisionId,
            'transaction_ligne_id' => $ligneId,
            'exercice' => $exercice,
            'montant' => $montant,
        ]);
    }

    /**
     * Garde du verrou (spec § 7) pour un geste qui écrit sur UNE ligne : refuse, par le message
     * unique MOTIF_PIECE_GELEE, si cette ligne porte un rattachement. Les gestes qui touchent la
     * pièce entière appellent assertPieceNonGelee(). Sites : le reclassement (Task 7) ; la
     * ventilation, affecterLigne() et supprimerAffectations() (Task 8c) ; la synchronisation HelloAsso
     * (Task 9).
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
     * REFUSE PAR DÉFAUT : la décision ne dépend que de l'identifiant de l'instance reçue, jamais
     * d'une relecture de la ligne. Une ligne supprimée logiquement, ou lue hors du contexte
     * d'association, est introuvable par une lecture ordinaire (suppression logique, scope tenant
     * fail-closed) : lire la ligne sous verrou et « ne rien trouver » ferait passer la garde, alors
     * que la clé étrangère du rattachement, elle, ignore tout scope. Les deux lectures lèvent donc
     * tous les scopes. Le verrou de ligne ne décide de rien : il exclut, et s'il ne trouvait rien,
     * la lecture des rattachements décide quand même.
     *
     * @throws RuntimeException MOTIF_PIECE_GELEE si la ligne porte un rattachement
     */
    public function assertLigneNonGelee(TransactionLigne $ligne): void
    {
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
     * Sites : l'édition, la suppression et l'annulation de TransactionService (Task 8a) ; l'extourne
     * (Task 8b). L'extourne aura une variante datée (D13 : une annulation datée après N reste permise) :
     * cette garde-ci refuse sans condition de date, et la Task 8b en décidera.
     *
     * RÈGLE DES SITES : l'appeler juste après le verrou d'exercice, SANS avoir verrouillé la pièce avant.
     * Elle prend elle-même, dans l'ordre canonique, tout ce qu'il faut : l'appelant qui verrouillerait la
     * pièce avant elle inverserait l'ordre des lots (voir ci-dessous). À appeler DANS la transaction de
     * l'appelant (voir assertLigneNonGelee()) : les verrous tiennent jusqu'à son commit.
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
     * La pièce rendue est lue tous scopes levés, elle aussi : supprimée logiquement ou d'une autre
     * association, elle est rendue quand même. Un appelant qui exige une pièce vivante de l'association
     * (update()) le contrôle lui-même.
     *
     * @throws RuntimeException MOTIF_PIECE_GELEE si l'une des lignes de la pièce porte un rattachement ;
     *                          MOTIF_ECRITURE_DE_RATTACHEMENT si la pièce est une écriture de rattachement
     * @throws ModelNotFoundException la pièce n'existe plus en base (supprimée physiquement)
     */
    public function assertPieceNonGelee(Transaction $piece): Transaction
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

        // Une écriture de rattachement ne se réécrit pas ici. Lu sur la pièce verrouillée : refuser sur
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
        // lecture par ligne. Une pièce sans ligne ne porte aucun rattachement : rien à lire.
        if ($ligneIds !== []
            && Provision::withoutGlobalScopes()->whereIn('transaction_ligne_id', $ligneIds)->lockForUpdate()->value('id') !== null) {
            throw new RuntimeException(self::MOTIF_PIECE_GELEE);
        }

        return $verrouillee;
    }

    /**
     * Verrouille la pièce puis la ligne (verrouillerPieceEtLigne()), et rend leur état verrouillé :
     * celui sur lequel l'éligibilité se rejuge et le montant se calcule. À appeler dans une
     * transaction, après les verrous d'exercice ; ils tiennent jusqu'au commit.
     *
     * Les refus ci-dessous sont ceux d'une CRÉATION : retirer() n'en reprend aucun, pour qu'un
     * rattachement dont l'origine a disparu reste retirable.
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
        ['piece' => $piece, 'ligne' => $ligneVerrouillee] = $this->verrouillerPieceEtLigne(
            (int) $ligne->transaction_id,
            (int) $ligne->id,
            inclureSupprimees: false,
        );

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

    /**
     * Le seul verrouillage de la pièce puis de la ligne, dans cet ordre, partagé par
     * rattacher() (qui juge ensuite ce qu'il a verrouillé) et retirer() (qui n'en juge rien).
     * Ne refuse jamais : une pièce ou une ligne introuvable est rendue nulle, et c'est à
     * l'appelant d'en décider.
     *
     * L'ordre est celui de TransactionService::update() (exercice, pièce, puis suppression des
     * lignes). Parmi les écritures qui touchent la pièce ou la ligne, seuls rattacher() et
     * retirer() prennent le verrou d'association : les autres (édition, annulation,
     * synchronisation HelloAsso) ne s'excluent que par ces deux verrous.
     *
     * @param  int|null  $pieceId  nul : la lecture préalable n'a pas trouvé la ligne (retirer()),
     *                             donc aucune pièce à verrouiller ; la ligne, elle, est quand même
     *                             cherchée sous verrou, et son absence est rendue nulle
     * @param  bool  $inclureSupprimees  vrai : lire aussi les pièces et lignes supprimées
     *                                   logiquement (withTrashed()). Le scope tenant reste appliqué.
     * @return array{piece: ?Transaction, ligne: ?TransactionLigne}
     */
    private function verrouillerPieceEtLigne(?int $pieceId, int $ligneId, bool $inclureSupprimees): array
    {
        $pieces = Transaction::query();
        $lignes = TransactionLigne::query();

        if ($inclureSupprimees) {
            $pieces->withTrashed();
            $lignes->withTrashed();
        }

        $piece = $pieceId !== null ? $pieces->whereKey($pieceId)->lockForUpdate()->first() : null;
        $ligne = $lignes->whereKey($ligneId)->lockForUpdate()->first();

        return ['piece' => $piece, 'ligne' => $ligne];
    }
}
