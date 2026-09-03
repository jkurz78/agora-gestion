<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Enums\JournalComptable;
use App\Models\ANouveauGeneration;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class FluxTresorerieBuilder
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Bornes de l'exercice, dérivées du paramétrage du tenant.
     *
     * Ce calcul était local et figé sur septembre-août, alors que le mois de
     * début appartient à l'association (`exercice_mois_debut`). Une
     * association en exercice civil ou décalé — mars-février dans l'hémisphère
     * sud — voyait donc ses montants pris sur la mauvaise période, en silence.
     *
     * ExerciceService est la seule source : il gère le décalage d'année, le
     * cas calendaire où l'exercice tient dans une seule année civile, et les
     * libellés correspondants.
     *
     * @return array{string, string}
     */
    private function exerciceDates(int $exercice): array
    {
        $range = $this->exerciceService->dateRange($exercice);

        return [$range['start']->toDateString(), $range['end']->toDateString()];
    }

    /**
     * Lignes de trésorerie de la période : toutes les écritures touchant un
     * compte de CLASSE 5, quel que soit le journal ou le `compte_id` de
     * l'en-tête.
     *
     * La trésorerie, c'est le solde des comptes financiers — les 51 (banques et
     * valeurs à l'encaissement) et les 53 (caisse). Un chèque reçu non remis et
     * les espèces en caisse sont de la trésorerie détenue, pas du bruit : c'est
     * précisément l'écart entre ce total et les seuls 512.
     *
     * Ce rapport lisait auparavant `transactions.montant_total` filtré sur le
     * journal (`vente`/`achat`), c'est-à-dire des ENGAGEMENTS. En partie double,
     * une dette fournisseur ne déplace aucun argent — c'est son règlement, en
     * journal `banque`, qui le fait, et ce journal était hors du scope. Le
     * rapport annonçait donc des mouvements de trésorerie qui n'avaient pas eu
     * lieu et ignorait ceux qui avaient eu lieu.
     *
     * La période est bornée comme dans SoldeService : on ne cumule pas depuis
     * l'origine, on lit l'exercice, et c'est l'à-nouveau (journal `an`, daté du
     * premier jour) qui porte le solde d'ouverture. Cumuler depuis l'origine
     * compterait deux fois les exercices antérieurs, déjà repris par l'AN.
     */
    private function lignesTresorerie(string $start, string $end): QueryBuilder
    {
        $query = DB::table('transaction_lignes as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('comptes as c', 'c.id', '=', 'l.compte_id')
            ->where('c.classe', 5)
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.date', [$start, $end]);

        if (! TenantContext::hasBooted()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('c.association_id', TenantContext::currentId());
    }

    /**
     * Mouvement net de trésorerie, agrégé PAR TRANSACTION avant d'être ventilé
     * en entrées et sorties.
     *
     * L'agrégation par transaction n'est pas un détail : une remise de chèques
     * débite 512 et crédite 5112, un virement interne débite un 512 et en
     * crédite un autre. Ce sont des transferts au sein de la trésorerie, de net
     * nul. Sommer les débits et les crédits séparément gonflerait à la fois les
     * encaissements et les décaissements d'un mouvement qui n'a fait entrer ni
     * sortir un euro. Le code précédent devait pour cette raison traiter les
     * virements internes à la main, des deux côtés du calcul ; ils s'annulent
     * désormais d'eux-mêmes.
     *
     * @return array{recettes: float, depenses: float, variation: float}
     */
    private function fluxDePeriode(string $start, string $end, bool $aNouveauSeulement): array
    {
        $rows = $this->lignesTresorerie($start, $end)
            ->when(
                $aNouveauSeulement,
                fn (QueryBuilder $q) => $q->where('t.journal', JournalComptable::AN->value),
                fn (QueryBuilder $q) => $q->where('t.journal', '!=', JournalComptable::AN->value),
            )
            ->groupBy('l.transaction_id', 't.type')
            ->selectRaw('l.transaction_id, t.type, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net')
            ->get();

        $recettes = 0.0;
        $depenses = 0.0;

        foreach ($rows as $row) {
            $net = round((float) $row->net, 2);

            if ($net === 0.0) {
                continue;
            }

            [$colonne, $valeur] = $this->ventiler($row->type, $net);

            if ($colonne === 'recette') {
                $recettes += $valeur;
            } else {
                $depenses += $valeur;
            }
        }

        return [
            'recettes' => round($recettes, 2),
            'depenses' => round($depenses, 2),
            'variation' => round($recettes - $depenses, 2),
        ];
    }

    /**
     * Range un mouvement dans la colonne « recettes » ou « dépenses ».
     *
     * Le classement suit le TYPE de la transaction, pas le signe du mouvement,
     * et le montant reste algébrique. C'est ce qui permet à une recette
     * négative — un remboursement — de rester une recette de −50 plutôt que de
     * devenir une dépense de +50 : les colonnes du rapport se lisent comme des
     * catégories de pièces, et toute une famille de tests d'audit garantit que
     * le signe n'est pas perdu en route.
     *
     * La variation est juste dans les deux cas ; c'est la répartition entre les
     * deux colonnes qui aurait changé.
     *
     * @return array{0: string, 1: float}
     */
    private function ventiler(string $type, float $net): array
    {
        return $type === 'recette'
            ? ['recette', $net]
            : ['depense', -$net];
    }

    /**
     * Trésorerie au premier jour de l'exercice.
     *
     * Deux régimes coexistent dans l'application, et SoldeService fait déjà le
     * même aiguillage :
     *
     *  - une génération d'à-nouveaux est active pour cet exercice : l'AN
     *    (journal `an`, daté du premier jour) porte la trésorerie reprise. On
     *    la lit, et on ne cumule surtout pas depuis l'origine — les exercices
     *    antérieurs sont déjà repris par l'AN, les compter en plus les
     *    doublerait ;
     *  - aucun à-nouveau : c'est le cas d'une association à son premier
     *    exercice. L'ouverture est alors le solde initial saisi sur les comptes
     *    bancaires, augmenté des mouvements de classe 5 antérieurs au début de
     *    l'exercice.
     *
     * Sans ce second cas, une association qui n'a pas encore clôturé verrait
     * son solde d'ouverture tomber à zéro.
     */
    private function soldeOuverture(int $exercice, string $start, string $end): float
    {
        if (ANouveauGeneration::activePourCible($exercice) !== null) {
            return $this->fluxDePeriode($start, $end, aNouveauSeulement: true)['variation'];
        }

        $soldeInitial = (float) CompteBancaire::sum('solde_initial');

        $anterieur = $this->fluxDePeriode('1900-01-01', Carbon::parse($start)->subDay()->toDateString(), aNouveauSeulement: false);

        return round($soldeInitial + $anterieur['variation'], 2);
    }

    /**
     * Mouvements bancaires non encore rattachés à un rapprochement.
     *
     * ATTENTION — le périmètre n'est pas celui de la variation. La variation
     * mesure « combien d'argent a bougé » et prend toute la classe 5. Le
     * rapprochement mesure « ce que la banque va présenter » et se limite aux
     * comptes qui ont un relevé, c'est-à-dire ceux rattachés à un
     * CompteBancaire.
     *
     * Un chèque reçu et non remis (5112) ou des espèces en caisse (530) sont
     * de la trésorerie détenue — ils comptent dans la variation — mais ne
     * figureront jamais individuellement sur un relevé : ils y apparaîtront le
     * jour de la remise, en une seule ligne, et c'est cette remise qui se
     * pointe. Les compter ici noierait le trésorier sous des écritures qu'il
     * ne pourra jamais pointer. Même raisonnement pour les encaissements
     * groupés dans un virement.
     *
     * Une dette ou une créance non réglée n'a rien à faire ici non plus : elle
     * n'a pas de ligne de trésorerie du tout.
     *
     * @return array{recettes: float, depenses: float, nb_recettes: int, nb_depenses: int, nets: array<int, float>}
     */
    private function mouvementsNonPointes(string $start, string $end): array
    {
        $rows = $this->lignesTresorerie($start, $end)
            ->whereNotNull('c.compte_bancaire_id')
            ->where('t.journal', '!=', JournalComptable::AN->value)
            ->whereNull('t.rapprochement_id')
            ->groupBy('l.transaction_id', 't.type')
            ->selectRaw('l.transaction_id, t.type, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net')
            ->get();

        $recettes = 0.0;
        $depenses = 0.0;
        $nbRecettes = 0;
        $nbDepenses = 0;
        $nets = [];

        foreach ($rows as $row) {
            $net = round((float) $row->net, 2);

            // Net nul : transfert interne à la trésorerie (remise, virement).
            // Il n'apparaît pas comme un mouvement à pointer.
            if ($net === 0.0) {
                continue;
            }

            $nets[(int) $row->transaction_id] = $net;

            [$colonne, $valeur] = $this->ventiler($row->type, $net);

            if ($colonne === 'recette') {
                $recettes += $valeur;
                $nbRecettes++;
            } else {
                $depenses += $valeur;
                $nbDepenses++;
            }
        }

        return [
            'recettes' => round($recettes, 2),
            'depenses' => round($depenses, 2),
            'nb_recettes' => $nbRecettes,
            'nb_depenses' => $nbDepenses,
            'nets' => $nets,
        ];
    }

    /**
     * Où se trouve la trésorerie de clôture.
     *
     * « Aucune écriture non pointée » est exact au sens du relevé bancaire,
     * mais muet sur les chèques encore dans le tiroir et les espèces en
     * caisse. Ces sommes sont de la trésorerie détenue — elles pèsent dans la
     * variation — sans être en banque, et seule la part bancaire se rapproche
     * d'un relevé. La synthèse les nomme donc séparément.
     *
     * @return array{banque: float, caisse: float, a_remettre: float}
     */
    private function decomposition(string $start, string $end): array
    {
        $rows = $this->lignesTresorerie($start, $end)
            ->groupBy('c.id', 'c.numero_pcg', 'c.compte_bancaire_id')
            ->selectRaw('c.numero_pcg, c.compte_bancaire_id, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net')
            ->get();

        $decomposition = ['banque' => 0.0, 'caisse' => 0.0, 'a_remettre' => 0.0];

        foreach ($rows as $row) {
            $net = round((float) $row->net, 2);

            // Un compte rattaché à un CompteBancaire a un relevé : c'est la
            // banque. Les 53X sont la caisse. Le reste de la classe 5 — les
            // 511X — est ce qui est reçu mais pas encore remis.
            $categorie = match (true) {
                $row->compte_bancaire_id !== null => 'banque',
                str_starts_with((string) $row->numero_pcg, '53') => 'caisse',
                default => 'a_remettre',
            };

            $decomposition[$categorie] += $net;
        }

        return array_map(fn (float $v): float => round($v, 2), $decomposition);
    }

    /**
     * Jambes de virements internes non encore pointées.
     *
     * Un virement ne fait ni entrer ni sortir d'argent : il est à somme nulle
     * sur la trésorerie et n'a rien à faire dans la variation. Mais ses deux
     * jambes se pointent INDÉPENDAMMENT — `rapprochement_source_id` et
     * `rapprochement_destination_id` sont deux colonnes distinctes — et tant
     * que l'une des deux ne l'est pas, elle justifie une partie de l'écart
     * entre le solde comptable et l'extrait de compte. C'est le seul endroit
     * du rapport où un virement doit apparaître.
     *
     * @return array{recettes: float, depenses: float, nb_recettes: int, nb_depenses: int}
     */
    private function virementsNonPointes(string $start, string $end): array
    {
        $virements = VirementInterne::query()
            ->whereBetween('date', [$start, $end])
            ->where(fn ($q) => $q->whereNull('rapprochement_source_id')
                ->orWhereNull('rapprochement_destination_id'))
            ->get();

        $recettes = 0.0;
        $depenses = 0.0;
        $nbRecettes = 0;
        $nbDepenses = 0;

        foreach ($virements as $virement) {
            $montant = round((float) $virement->montant, 2);

            // Jambe sortante non pointée : l'argent a quitté le compte source
            // sans que le relevé l'ait confirmé.
            if ($virement->rapprochement_source_id === null) {
                $depenses += $montant;
                $nbDepenses++;
            }

            if ($virement->rapprochement_destination_id === null) {
                $recettes += $montant;
                $nbRecettes++;
            }
        }

        return [
            'recettes' => round($recettes, 2),
            'depenses' => round($depenses, 2),
            'nb_recettes' => $nbRecettes,
            'nb_depenses' => $nbDepenses,
        ];
    }

    /**
     * Chemin du résultat de l'exercice à la variation de trésorerie.
     *
     * Les deux chiffres répondent à deux questions et n'ont aucune raison
     * d'être égaux : une créance est un produit sans encaissement, une
     * acquisition d'immobilisation un décaissement sans charge, une dotation
     * une charge sans décaissement. Sans ce pont, l'écart passe pour une
     * erreur alors qu'il est la mesure même du décalage entre l'engagement et
     * l'argent.
     *
     * La construction s'appuie sur l'identité de la partie double : la somme
     * des variations de TOUS les comptes est nulle, donc la variation de la
     * classe 5 est l'opposé de celle de tout le reste. On nomme les postes qui
     * parlent au trésorier — créances, dettes, immobilisations, dotations — et
     * la ligne « autres » absorbe le solde. Le pont ne peut donc pas ne pas
     * boucler, y compris sur des écritures qu'on n'a pas pensé à nommer.
     *
     * @return array{resultat: float, creances: float, dettes: float, immobilisations: float, dotations: float, autres: float}
     */
    private function pontResultat(string $start, string $end, float $variation): array
    {
        $parCompte = DB::table('transaction_lignes as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('comptes as c', 'c.id', '=', 'l.compte_id')
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where('t.journal', '!=', JournalComptable::AN->value)
            ->whereBetween('t.date', [$start, $end])
            ->when(
                TenantContext::hasBooted(),
                fn ($q) => $q->where('c.association_id', TenantContext::currentId()),
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->groupBy('c.numero_pcg', 'c.classe')
            ->selectRaw('c.numero_pcg, c.classe, COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net')
            ->get();

        $produits = 0.0;
        $charges = 0.0;
        $creances = 0.0;
        $dettes = 0.0;
        $immobilisations = 0.0;
        $dotations = 0.0;

        foreach ($parCompte as $ligne) {
            $net = round((float) $ligne->net, 2);
            $numero = (string) $ligne->numero_pcg;
            $classe = (int) $ligne->classe;

            match (true) {
                // Un produit crédite : son net débiteur est négatif.
                $classe === 7 => $produits += -$net,
                // Une dotation est une charge sans décaissement : on la
                // distingue des autres charges pour la rendre au pont.
                str_starts_with($numero, '681') => $dotations += $net,
                $classe === 6 => $charges += $net,
                str_starts_with($numero, '411') => $creances += $net,
                str_starts_with($numero, '401') => $dettes += $net,
                // Classe 2 hors amortissements : l'acquisition elle-même.
                $classe === 2 && ! str_starts_with($numero, '28') => $immobilisations += $net,
                default => null,
            };
        }

        $resultat = round($produits - $charges - $dotations, 2);

        $pont = [
            'resultat' => $resultat,
            // Une créance qui augmente retarde l'encaissement : elle éloigne la
            // trésorerie du résultat, d'où le signe opposé à sa variation.
            'creances' => round(-$creances, 2),
            'dettes' => round(-$dettes, 2),
            'immobilisations' => round(-$immobilisations, 2),
            // Une dotation est une charge SANS décaissement : elle a diminué le
            // résultat sans toucher la trésorerie, il faut donc la rendre.
            'dotations' => round($dotations, 2),
        ];

        $pont['autres'] = round($variation - array_sum($pont), 2);

        return $pont;
    }

    /**
     * Libellé de la pièce d'origine d'un règlement, retrouvé par le lettrage.
     *
     * Une T2 de règlement porte un libellé technique — « Règlement fournisseur
     * #216 » — là où la facture d'origine dit « Kaligrafik — Facture FA000696,
     * cartes-tampons ». C'est ce dernier qu'on veut sous les yeux en pointant
     * un relevé.
     *
     * On passe par le lettrage plutôt que par le « #216 » du libellé : le
     * numéro y est de la mise en forme, pas une donnée, et le lettrage est le
     * lien comptable réel entre la dette et son règlement. Deux requêtes en
     * tout, quelle que soit la taille de la liste.
     *
     * @param  list<int>  $transactionIds
     * @return array<int, string>
     */
    private function libellesDesPiecesSource(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        // Lignes de poste tiers lettrées des règlements considérés.
        $lignes = DB::table('transaction_lignes as l')
            ->join('comptes as c', 'c.id', '=', 'l.compte_id')
            ->whereIn('l.transaction_id', $transactionIds)
            ->whereNotNull('l.lettrage_code')
            ->where('c.classe', 4)
            ->whereNull('l.deleted_at')
            ->select('l.transaction_id', 'l.compte_id', 'l.lettrage_code')
            ->get();

        if ($lignes->isEmpty()) {
            return [];
        }

        // Contreparties : même compte, même code, autre transaction.
        $contreparties = DB::table('transaction_lignes as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->whereIn('l.lettrage_code', $lignes->pluck('lettrage_code')->unique()->all())
            ->whereNotIn('l.transaction_id', $transactionIds)
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->select('l.lettrage_code', 'l.compte_id', 't.libelle')
            ->get()
            ->keyBy(fn ($row): string => $row->lettrage_code.'|'.$row->compte_id);

        $libelles = [];

        foreach ($lignes as $ligne) {
            $source = $contreparties->get($ligne->lettrage_code.'|'.$ligne->compte_id);

            if ($source !== null) {
                $libelles[(int) $ligne->transaction_id] = (string) $source->libelle;
            }
        }

        return $libelles;
    }

    /**
     * Nom du tiers d'une écriture, en retombant sur ses lignes.
     *
     * Les écritures de règlement générées par EcritureGenerator laissent
     * `tiers_id` nul sur l'en-tête et le portent sur la ligne de poste tiers
     * (401 fournisseur, 411 client) : c'est là que se trouve le nom utile au
     * moment de rapprocher un relevé.
     */
    private function tiersDe(Transaction $transaction): string
    {
        if ($transaction->tiers !== null) {
            return $transaction->tiers->displayName();
        }

        foreach ($transaction->lignes as $ligne) {
            if ($ligne->tiers !== null) {
                return $ligne->tiers->displayName();
            }
        }

        return '—';
    }

    /**
     * État de flux de trésorerie consolidé.
     *
     * @return array{exercice: array, synthese: array, rapprochement: array, mensuel: list<array>, ecritures_non_pointees: list<array>}
     */
    public function fluxTresorerie(int $exercice): array
    {
        [$start, $end] = $this->exerciceDates($exercice);

        // --- Exercice info ---
        $exerciceModel = Exercice::where('annee', $exercice)->first();
        $exerciceInfo = [
            'annee' => $exercice,
            'label' => $exercice.'-'.($exercice + 1),
            'date_debut' => $start,
            'date_fin' => $end,
            'is_cloture' => $exerciceModel?->isCloture() ?? false,
            'date_cloture' => $exerciceModel?->date_cloture?->format('d/m/Y'),
        ];

        // --- Solde d'ouverture : l'à-nouveau de l'exercice ---
        // Il est daté du premier jour de la période et porte, en classe 5, la
        // trésorerie reprise de l'exercice précédent. On ne le reconstitue plus
        // à rebours depuis le solde actuel : cette reconstitution s'appuyait sur
        // `transactions.compte_id`, nul sur la quasi-totalité des règlements
        // séparés, donc sur une base différente de celle de la variation — les
        // deux erreurs ne s'annulaient pas, elles se composaient.
        $soldeOuverture = $this->soldeOuverture($exercice, $start, $end);

        // --- Mouvements réels de la période (hors à-nouveau) ---
        $flux = $this->fluxDePeriode($start, $end, aNouveauSeulement: false);
        $totalRecettes = $flux['recettes'];
        $totalDepenses = $flux['depenses'];
        $variation = $flux['variation'];

        // Invariant : ouverture + variation = solde de clôture, par construction
        // — les deux termes sont désormais lus sur la même base, le grand livre
        // des comptes de classe 5.
        $soldeTheorique = round($soldeOuverture + $variation, 2);

        // --- Rapprochement : ce que la banque va effectivement présenter ---
        // Le filtre portait sur `transactions.compte_id`, un champ d'en-tête
        // posé de façon inégale : une dette fournisseur le porte alors qu'elle
        // n'ira jamais sur un relevé, et son règlement — le seul vrai mouvement
        // bancaire — ne le porte le plus souvent pas. Le rapport listait donc
        // la dette ET son règlement pour un unique paiement, et faisait figurer
        // des créances qui resteraient « non pointées » indéfiniment.
        //
        // Une écriture non pointée, c'est une transaction qui a bougé la
        // trésorerie et qui n'est pas encore rattachée à un rapprochement.
        $nonPointees = $this->mouvementsNonPointes($start, $end);

        // Les jambes de virements non pointées s'ajoutent : elles justifient
        // aussi l'écart entre le solde comptable et l'extrait de compte.
        $virements = $this->virementsNonPointes($start, $end);

        $recettesNonPointees = round($nonPointees['recettes'] + $virements['recettes'], 2);
        $depensesNonPointees = round($nonPointees['depenses'] + $virements['depenses'], 2);
        $nbRecettesNonPointees = $nonPointees['nb_recettes'] + $virements['nb_recettes'];
        $nbDepensesNonPointees = $nonPointees['nb_depenses'] + $virements['nb_depenses'];

        // Du total détenu au solde des relevés, en deux temps.
        //
        // 1. Retirer ce qui n'est pas en banque. Le solde théorique porte toute
        //    la trésorerie — chèques encore en main (5112) et espèces en caisse
        //    (530) compris. Aucun relevé ne les connaît. Sans cette
        //    soustraction, le bas de page ne pouvait pas tomber juste.
        $decomposition = $this->decomposition($start, $end);
        $soldeBanque = round(
            $soldeTheorique - $decomposition['a_remettre'] - $decomposition['caisse'],
            2
        );

        // 2. Corriger des mouvements que la banque n'a pas encore passés : une
        //    dépense émise non pointée laisse l'argent sur le relevé, une
        //    recette non pointée ne s'y trouve pas encore.
        $soldeReel = round($soldeBanque - $recettesNonPointees + $depensesNonPointees, 2);

        // --- Ventilation mensuelle ---
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $yearExpr = $isSqlite ? "CAST(strftime('%Y', date) AS INTEGER)" : 'YEAR(date)';
        $monthExpr = $isSqlite ? "CAST(strftime('%m', date) AS INTEGER)" : 'MONTH(date)';

        // Même agrégation par transaction que fluxDePeriode(), déclinée par mois :
        // le net d'une transaction est calculé d'abord, puis ventilé en entrée
        // ou sortie — sans quoi remises et virements internes gonfleraient les
        // deux colonnes d'un mouvement de net nul.
        $mensuelRows = collect($this->lignesTresorerie($start, $end)
            ->where('t.journal', '!=', JournalComptable::AN->value)
            // `t.date` doit figurer dans le GROUP BY, même si une transaction
            // n'a qu'une date : la PRODUCTION tourne sous MariaDB, qui refuse
            // sous ONLY_FULL_GROUP_BY toute colonne non agrégée absente du
            // GROUP BY. MySQL 8 l'acceptait en détectant que `t.date` dépend
            // fonctionnellement de `l.transaction_id` (clé primaire jointe) —
            // MariaDB n'implémente pas cette détection. D'où un rapport vert en
            // local et un 500 en production (erreur 1055).
            ->groupBy('l.transaction_id', 't.date')
            ->selectRaw("
                l.transaction_id,
                {$yearExpr} as annee, {$monthExpr} as mois_num,
                COALESCE(SUM(l.debit),0) - COALESCE(SUM(l.credit),0) AS net
            ")
            ->get())
            ->groupBy(fn ($row) => $row->annee.'-'.str_pad((string) $row->mois_num, 2, '0', STR_PAD_LEFT))
            ->map(function ($rows): object {
                $recettes = 0.0;
                $depenses = 0.0;

                foreach ($rows as $row) {
                    $net = round((float) $row->net, 2);
                    if ($net > 0) {
                        $recettes += $net;
                    } elseif ($net < 0) {
                        $depenses += -$net;
                    }
                }

                return (object) ['recettes' => $recettes, 'depenses' => $depenses];
            });

        $mensuel = [];
        $cumul = $soldeOuverture;
        $moisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        for ($i = 0; $i < 12; $i++) {
            // Rotation des 12 mois à partir du mois de début d'exercice, qui
            // est un RÉGLAGE de l'association. Septembre était codé en dur ici
            // (`(($i + 8) % 12) + 1`), si bien qu'une association en exercice
            // civil voyait ses mois dans le désordre. Même formule que
            // VentilationFinanciereService, seul endroit qui le faisait juste.
            $moisNum = (($moisDebut - 1 + $i) % 12) + 1;
            $annee = $moisNum >= $moisDebut ? $exercice : $exercice + 1;
            $key = $annee.'-'.str_pad((string) $moisNum, 2, '0', STR_PAD_LEFT);

            $recettes = round((float) ($mensuelRows[$key]->recettes ?? 0), 2);
            $depenses = round((float) ($mensuelRows[$key]->depenses ?? 0), 2);
            $solde = round($recettes - $depenses, 2);
            $cumul = round($cumul + $solde, 2);

            $moisLabel = ucfirst(Carbon::create($annee, $moisNum, 1)->translatedFormat('F Y'));

            $mensuel[] = [
                'mois' => $moisLabel,
                'recettes' => $recettes,
                'depenses' => $depenses,
                'solde' => $solde,
                'cumul' => $cumul,
            ];
        }

        // --- Liste des écritures non pointées (PDF) ---
        // Strictement les mêmes transactions que celles totalisées ci-dessus :
        // la liste et le total ne peuvent plus diverger. Le montant affiché est
        // le mouvement de trésorerie de la pièce, pas son `montant_total` —
        // pour une dépense partiellement réglée, ce sont deux chiffres
        // différents, et seul le premier figurera sur le relevé.
        $nets = $nonPointees['nets'];
        $libellesSource = $this->libellesDesPiecesSource(array_keys($nets));

        $ecrituresNonPointees = Transaction::whereIn('id', array_keys($nets))
            ->with(['tiers', 'lignes.tiers'])
            ->orderBy('date')
            ->get()
            ->map(function (Transaction $t) use ($nets, $libellesSource): array {
                $net = $nets[(int) $t->id] ?? 0.0;

                return [
                    'numero_piece' => $t->numero_piece,
                    'date' => $t->date->format('d/m/Y'),
                    // Une T2 de règlement ne porte pas de tiers sur son en-tête :
                    // il vit sur sa ligne de poste tiers (401 ou 411). Sans ce
                    // repli, la ligne s'affichait « — · Règlement fournisseur
                    // #216 », illisible pour qui rapproche son relevé.
                    'tiers' => $this->tiersDe($t),
                    'libelle' => $libellesSource[(int) $t->id] ?? $t->libelle,
                    'type' => $net >= 0 ? 'recette' : 'depense',
                    'montant' => abs($net),
                ];
            })
            ->values()
            ->all();

        return [
            'exercice' => $exerciceInfo,
            'synthese' => [
                'solde_ouverture' => $soldeOuverture,
                'total_recettes' => $totalRecettes,
                'total_depenses' => $totalDepenses,
                'variation' => $variation,
                'solde_theorique' => $soldeTheorique,
                'decomposition' => $decomposition,
                'pont_resultat' => $this->pontResultat($start, $end, $variation),
            ],
            'rapprochement' => [
                'solde_theorique' => $soldeTheorique,
                'solde_banque' => $soldeBanque,
                'recettes_non_pointees' => $recettesNonPointees,
                'nb_recettes_non_pointees' => $nbRecettesNonPointees,
                'depenses_non_pointees' => $depensesNonPointees,
                'nb_depenses_non_pointees' => $nbDepensesNonPointees,
                'solde_reel' => $soldeReel,
            ],
            'mensuel' => $mensuel,
            'ecritures_non_pointees' => $ecrituresNonPointees,
        ];
    }
}
