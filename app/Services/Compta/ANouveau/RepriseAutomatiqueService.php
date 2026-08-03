<?php

declare(strict_types=1);

namespace App\Services\Compta\ANouveau;

use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauGeneration;
use App\Models\CompteBancaire;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Reprend les soldes d'ouverture du tenant courant, quand il n'y a rien à
 * arbitrer.
 *
 * Saisir un solde d'ouverture est un geste de gestion ordinaire ; le traduire en
 * écriture ne devrait pas exiger une commande d'administration système. Dans le
 * cas nominal il n'y a d'ailleurs rien à trancher : le montant vient d'être saisi
 * par l'utilisateur, l'exercice se déduit de la date, et les deux configurations
 * dangereuses — double comptage et mauvaise année — sont refusées en amont par
 * BootstrapANouveauService.
 *
 * Ce service s'abstient donc, sans erreur, dans les cinq cas où l'opération est
 * inutile ou demande une décision humaine. Il n'écrit que lorsque le calcul est
 * net. La commande compta:bootstrap-an reste disponible pour les reprises
 * délicates et pour l'audit.
 */
final class RepriseAutomatiqueService
{
    public function __construct(
        private readonly BootstrapANouveauService $bootstrap,
        private readonly ANouveauService $anouveau,
    ) {}

    /**
     * @return ANouveauGeneration|null La pièce créée, ou null si rien n'était à faire.
     */
    public function tenter(?User $acteur): ?ANouveauGeneration
    {
        if ($acteur === null) {
            return null;
        }

        // Rien à reprendre : une association qui démarre à zéro traverse cette
        // étape sans rien faire. C'est le cas nominal, pas une exception.
        $aDesSoldes = CompteBancaire::query()
            ->where('solde_initial', '<>', 0)
            ->exists();

        if (! $aDesSoldes) {
            return null;
        }

        // Déjà repris. Un compte ajouté après la reprise n'est volontairement pas
        // rattrapé : amender une écriture comptable existante est une décision
        // humaine, et la garde de clôture la signalera.
        $dejaRepris = ANouveauGeneration::query()
            ->where('origine', OrigineANouveau::RepriseInitiale)
            ->where('statut', StatutANouveau::Active)
            ->exists();

        if ($dejaRepris) {
            return null;
        }

        // Saisie en cours. Reprendre l'existant est un geste multi-comptes, et
        // l'IHM enregistre un compte à la fois : entre deux enregistrements,
        // l'instantané mélange les dates nouvelles et les anciennes. Comme
        // exerciceSuggere() retient la date la plus tardive, la reprise partirait
        // sur l'exercice que désigne le compte pas encore corrigé, et reporterait
        // les autres d'une année entière.
        //
        // Constaté en recette le 2026-08-01 : deux comptes à redater au 31/08/2024,
        // huit secondes entre les deux enregistrements, reprise créée sur
        // l'exercice 2025 au premier — puis abstention au second, la porte étant
        // refermée par le contrôle ci-dessus. Comparer les exercices désignés et
        // non les dates laisse passer le cas légitime de dates différentes au sein
        // d'un même exercice, celui du site de démonstration.
        $exercicesVises = $this->bootstrap->exercicesVises();

        if (count($exercicesVises) > 1) {
            Log::info('[Reprise] Reprise automatique différée, les comptes ne désignent pas le même exercice.', [
                'exercices_vises' => $exercicesVises,
            ]);

            return null;
        }

        // Exercice indéterminable : la date de solde ne tombe dans aucun exercice
        // connu. Rien à faire tant que l'exercice n'existe pas.
        $exercice = $this->bootstrap->exerciceSuggere();

        if ($exercice === null) {
            return null;
        }

        try {
            $apercu = $this->bootstrap->preview($exercice);
        } catch (ANouveauInvalideException $exception) {
            // Ambiguïté réelle — mouvements le jour même de la date de référence,
            // ou configuration qui doublerait les montants. Seul un humain
            // tranche : on laisse la garde de clôture le signaler.
            Log::info('[Reprise] Reprise automatique impossible, arbitrage requis.', [
                'exercice' => $exercice,
                'motif' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->anouveau->persister($apercu, OrigineANouveau::RepriseInitiale, $acteur);
    }
}
