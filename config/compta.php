<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Moteur comptable : partie double
    |--------------------------------------------------------------------------
    |
    | Quand cette option est activée, les rapports (CompteResultatBuilder, etc.)
    | lisent les colonnes partie double (transaction_lignes.compte_id + comptes.classe
    | + debit/credit) au lieu des colonnes legacy (sous_categorie_id + montant).
    |
    | Par défaut : false (sécurité — le legacy est actif jusqu'à la fin du backfill
    | et de la validation en préprod). Basculer à true manuellement une fois confiant.
    |
    | Override via .env :
    |   COMPTA_USE_PARTIE_DOUBLE=true
    |
    | Supprimé en sous-slice 1d après cutover prod final.
    |
    */
    'use_partie_double' => (bool) env('COMPTA_USE_PARTIE_DOUBLE', false),

];
