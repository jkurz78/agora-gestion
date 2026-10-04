<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Datasets partagés des tests du rattachement à l'exercice (CCA/PCA)
|--------------------------------------------------------------------------
|
| [classe du compte de résultat, montant de la ligne d'origine] : PCA sur un produit,
| CCA sur une charge. Utilisé par RattachementEcrituresTest et RattachementExerciceServiceTest.
*/

dataset('rattachement_pca_cca', [
    'PCA' => [7, 175.0],
    'CCA' => [6, 90.0],
]);
