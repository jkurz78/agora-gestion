<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\JournalComptable;
use App\Models\Extourne;
use App\Models\Provision;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Rattachement d'un produit ou d'une charge à l'exercice suivant (CCA/PCA).
 *
 * Toutes les gardes d'éligibilité de la LIGNE vivent dans motifDeRefus() : le
 * service, l'écran et la requête de détection s'y réfèrent, de sorte qu'une ligne
 * proposée à l'écran est acceptée par le service, et réciproquement. L'ouverture
 * de la PÉRIODE se contrôle à part : sous verrou dans rattacher() (exercices N et
 * N+1), et par période à l'écran (Task 11). Une ligne éligible dont l'exercice, ou
 * le suivant, est clôturé est donc refusée par rattacher() sans que motifDeRefus()
 * l'ait écartée.
 *
 * Référence : docs/specs/2026-09-30-provisions-rattachement-exercice-design.md
 * (D4, D9, D13, D14 pour les gardes ; § 5 pour les verrous d'exercice ; § 7 pour
 * la résolution de la ligne).
 */
final class RattachementExerciceService
{
    /** Formulation unique du refus, partagée avec CandidatsRattachementQuery. */
    public const MOTIF_AFFECTATIONS = 'Cette ligne est ventilée par affectations : son rattachement n’est pas encore pris en charge.';

    private const MOTIF_DEJA_RATTACHEE = 'Cette ligne est déjà rattachée à l’exercice suivant : retirez son rattachement avant de le refaire.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly ProvisionPDService $provisionPD,
    ) {}

    /**
     * Rend le motif pour lequel cette ligne n'est pas rattachable, ou null.
     *
     * Les messages sont destinés à l'utilisateur : ils expliquent et, quand il y en
     * a une, indiquent la sortie. L'ordre des gardes compte : la plus spécifique
     * passe en premier, de sorte que chaque ligne reçoit le motif qui la décrit.
     */
    public function motifDeRefus(TransactionLigne $ligne): ?string
    {
        $transaction = $ligne->transaction;

        if ($transaction === null) {
            return 'Cette ligne n’est rattachée à aucune pièce.';
        }

        // AVANT D14 : les écritures de rattachement sont elles-mêmes en journal OD,
        // et c'est ce motif-ci qu'elles doivent recevoir (AC-11).
        if ($transaction->provision_id !== null) {
            return 'Cette ligne appartient à une écriture de rattachement : elle ne peut pas être rattachée à son tour.';
        }

        // D14 : une écriture d'inventaire ne se rattache pas.
        if ($transaction->journal === JournalComptable::Od) {
            return 'Cette ligne appartient à une écriture d’inventaire (journal des opérations diverses) : '
                .'seule une ligne d’une pièce d’achat, de vente ou de banque peut être rattachée à l’exercice suivant.';
        }

        // D13 : même test que ReclassementLigneService::reclasser().
        if ($transaction->extournee_at !== null
            || $transaction->type_ecriture === 'extourne'
            || Extourne::where('transaction_extourne_id', (int) $transaction->id)->exists()) {
            return 'Cette pièce a été annulée, ou elle est elle-même une annulation : '
                .'la rattacher à l’exercice suivant retirerait deux fois le même montant. '
                .'S’il existe une pièce qui la remplace, rattachez celle-là.';
        }

        $compte = $ligne->compte;

        if ($compte === null || ! in_array((int) $compte->classe, [6, 7], true)) {
            return 'Seule une ligne de charge ou de produit peut être rattachée.';
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
                .') : son rattachement accentuerait son effet au lieu de l’annuler.';
        }

        // D4 : la ventilation vit dans les affectations, la ligne n'a pas
        // d'opération propre à hériter.
        if ($ligne->affectations()->exists()) {
            return self::MOTIF_AFFECTATIONS;
        }

        if ($ligne->estRattachee()) {
            return self::MOTIF_DEJA_RATTACHEE;
        }

        return null;
    }

    /** Variante par identifiant : la ligne est résolue dans l'association courante. */
    public function rattacherParId(int $ligneId, ?string $notes, User $auteur): Provision
    {
        // Résolution explicite dans le tenant (§ 7) : un identifiant forgé d'une
        // autre association est traité comme inexistant, pas servi.
        // TransactionLigneTenantScope le filtre aussi : deux filets, volontairement.
        $ligne = TransactionLigne::whereHas(
            'transaction',
            fn ($q) => $q->where('association_id', (int) TenantContext::currentId())
        )->find($ligneId);

        if ($ligne === null) {
            throw new RuntimeException('Cette ligne n’existe pas.');
        }

        return $this->rattacher($ligne, $notes, $auteur);
    }

    public function rattacher(TransactionLigne $ligne, ?string $notes, User $auteur): Provision
    {
        $motif = $this->motifDeRefus($ligne);

        if ($motif !== null) {
            throw new RuntimeException($motif);
        }

        $transaction = $ligne->transaction;
        $exercice = $this->exerciceService->anneeForDate($transaction->date);
        $montant = round(max((float) $ligne->debit, (float) $ligne->credit), 2);

        $provision = DB::transaction(function () use ($ligne, $exercice, $montant, $notes, $auteur): Provision {
            // Ordre canonique de cloturer() et du § 5 : association, exercice source,
            // exercice cible, puis écritures. La dotation vit dans N, l'extourne dans
            // N+1 : les deux doivent être ouverts. Un exercice absent n'est pas clôturé
            // (l'extourne est générée même si N+1 n'existe pas encore).
            $this->exerciceService->assertOuvertPourEcriture($exercice);
            $this->exerciceService->assertOuvertVerrouille($exercice + 1);

            // Verrou de la ligne (§ 4). Deux rattacher() de la même association sont
            // en pratique déjà sérialisés par le verrou d'association pris plus haut ;
            // celui-ci tient la ligne elle-même jusqu'au commit.
            TransactionLigne::whereKey((int) $ligne->id)->lockForUpdate()->first();

            // Relu sous les verrous : le contrôle de motifDeRefus(), fait avant la
            // transaction, a pu être dépassé par un rattachement validé entre-temps.
            //
            // Cette lecture n'est PAS verrouillante. Sous REPEATABLE READ (MariaDB), elle
            // lit le cliché de la transaction, pris au plus tôt après le verrou
            // d'association (la sous-requête tenant du verrou de ligne n'est pas, elle,
            // verrouillante) : un rattachement concurrent, sérialisé par ce verrou, est
            // donc déjà validé quand le cliché est pris. Si un appelant enveloppait un jour
            // rattacher() dans sa propre transaction, après des lectures ordinaires, le
            // cliché pourrait précéder ce verrou et le recontrôle manquer un rattachement
            // concurrent.
            if ($ligne->estRattachee()) {
                throw new RuntimeException(self::MOTIF_DEJA_RATTACHEE);
            }

            // `libelle` reste nul : en mode adossé le libellé est celui de la ligne
            // d'origine, lu par Provision::libelleEffectif(), comme le compte,
            // l'opération et la séance (D3). Le recopier créerait une seconde source.
            $provision = Provision::create([
                'association_id' => (int) TenantContext::currentId(),
                'exercice' => $exercice,
                'transaction_ligne_id' => (int) $ligne->id,
                'montant' => $montant,
                'notes' => $notes,
                'saisi_par' => (int) $auteur->id,
            ]);

            $this->provisionPD->generer($provision);

            return $provision;
        });

        Log::info('[Rattachement] écriture de rattachement créée', [
            'provision_id' => (int) $provision->id,
            'transaction_ligne_id' => (int) $ligne->id,
            'cas' => $provision->cas()->value,
            'exercice' => $exercice,
            'montant' => $montant,
        ]);

        return $provision;
    }
}
