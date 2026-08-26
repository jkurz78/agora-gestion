<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;

/**
 * Les 21 réglages persistés d'AssociationForm et leur destination unique.
 *
 * Un champ oublié dans la découpe ne produit AUCUNE erreur : il rend seulement un
 * réglage inatteignable. D'où ce test champ par champ, jamais en volume.
 *
 * L'invariant vérifié : un réglage vit dans EXACTEMENT UN composant, sa source
 * ou sa destination — jamais zéro (perdu au déplacement, devenu inatteignable
 * sans aucune erreur), jamais deux (migration à moitié faite, deux écrans
 * éditant le même champ, le dernier enregistré gagnant en silence). C'est vrai
 * avant la migration comme après — donc pas besoin de sauter les destinations
 * qui n'existent pas encore : property_exists() sur une classe absente renvoie
 * simplement faux via class_exists(), ce qui laisse le réglage sur sa source,
 * conforme à l'invariant. Un simple class_exists() comme signal de « migration
 * faite » se trompe dès qu'une destination pré-existe pour un autre usage
 * (ex. SmtpForm, déjà là pour le transport SMTP avant même d'accueillir
 * email_from/email_from_name).
 */
$reglages = [
    ['nom', AssociationForm::class],
    ['adresse', AssociationForm::class],
    ['code_postal', AssociationForm::class],
    ['ville', AssociationForm::class],
    ['email', AssociationForm::class],
    ['telephone', AssociationForm::class],
    ['logo_path', AssociationForm::class],
    ['cachet_signature_path', AssociationForm::class],
    ['siret', AssociationForm::class],
    ['forme_juridique', AssociationForm::class],
    ['facture_conditions_reglement', 'App\Livewire\Parametres\FacturationForm'],
    ['facture_mentions_legales', 'App\Livewire\Parametres\FacturationForm'],
    ['facture_mentions_penalites', 'App\Livewire\Parametres\FacturationForm'],
    ['facture_compte_bancaire_id', 'App\Livewire\Parametres\FacturationForm'],
    ['url_site_web', 'App\Livewire\Parametres\LiensPublicsForm'],
    ['url_renouvellement_adhesion', 'App\Livewire\Parametres\LiensPublicsForm'],
    ['url_nouveau_don', 'App\Livewire\Parametres\LiensPublicsForm'],
    ['anthropic_api_key', 'App\Livewire\Parametres\OcrIaForm'],
    ['invoice_ocr_model', 'App\Livewire\Parametres\OcrIaForm'],
    ['email_from', 'App\Livewire\Parametres\SmtpForm'],
    ['email_from_name', 'App\Livewire\Parametres\SmtpForm'],
];

dataset('reglages', $reglages);

it('chaque réglage vit dans exactement un composant', function (string $propriete, string $composant): void {
    $surSource = property_exists(AssociationForm::class, $propriete);

    if ($composant === AssociationForm::class) {
        expect($surSource)->toBeTrue("{$propriete} devrait rester sur AssociationForm");

        return;
    }

    $surDestination = class_exists($composant) && property_exists($composant, $propriete);

    expect($surSource || $surDestination)->toBeTrue(
        "{$propriete} n'existe NULLE PART : ni sur AssociationForm, ni sur {$composant}. Réglage perdu.",
    );

    expect($surSource && $surDestination)->toBeFalse(
        "{$propriete} existe des DEUX côtés : AssociationForm et {$composant}. Deux écrans éditeraient le même champ.",
    );
})->with('reglages');

/**
 * Garde anti-vidage : si une ligne disparaît du jeu de données, le test
 * ci-dessus passerait quand même — il vérifierait juste moins de choses,
 * sans rien signaler. Ce second test fixe le compte à 21 en dur : toute
 * suppression (ou ajout non voulu) fait rougir la suite.
 */
it('le jeu de données reglages couvre exactement les 21 réglages persistés', function () use ($reglages): void {
    expect($reglages)->toHaveCount(21);
});
