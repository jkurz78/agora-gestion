<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

/**
 * Deuxième passe d'anonymisation (après anonymize-tiers.sql).
 *
 * - Remplit les données médicales chiffrées avec des valeurs fictives
 * - Corrige les prénoms des tiers participants pour respecter le sexe (M/F)
 */
final class AnonymizeMedicalDataCommand extends Command
{
    protected $signature = 'staging:anonymize-medical';

    protected $description = 'Anonymise les données médicales chiffrées et corrige les prénoms selon le sexe';

    /** @var list<string> */
    private const PRENOMS_M = [
        'Jean', 'Pierre', 'François', 'Michel', 'Philippe', 'Patrick', 'Nicolas',
        'Christophe', 'Laurent', 'Thierry', 'Éric', 'Frédéric', 'Olivier', 'David',
        'Sébastien', 'Alexandre', 'Thomas', 'Antoine', 'Julien', 'Maxime',
        'Romain', 'Hugo', 'Lucas', 'Gabriel', 'Louis', 'Arthur', 'Raphaël',
        'Léo', 'Nathan', 'Mathis', 'Adam', 'Paul', 'Mathieu', 'Théo',
        'Clément', 'Benjamin', 'Vincent', 'Alexis', 'Simon', 'Victor',
        'Adrien', 'Quentin', 'Florian', 'Dylan', 'Jordan', 'Kévin',
        'Jérôme', 'Yann', 'Arnaud', 'Guillaume', 'Damien', 'Aurélien',
        'Fabien', 'Xavier', 'Denis', 'Cédric', 'Mehdi', 'Karim',
        'Youssef', 'Omar', 'Ali', 'Rachid', 'Moussa', 'Ibrahim',
        'Rémi', 'Bastien', 'Axel', 'Enzo',
    ];

    /** @var list<string> */
    private const PRENOMS_F = [
        'Marie', 'Catherine', 'Isabelle', 'Nathalie', 'Sophie', 'Véronique',
        'Émilie', 'Céline', 'Sandrine', 'Valérie', 'Stéphanie', 'Caroline',
        'Julie', 'Aurélie', 'Mélanie', 'Charlotte', 'Camille', 'Léa',
        'Marine', 'Chloé', 'Clara', 'Alice', 'Emma', 'Louise',
        'Manon', 'Inès', 'Jade', 'Sarah', 'Laura', 'Pauline',
        'Anaïs', 'Margaux', 'Justine', 'Océane', 'Amandine', 'Élisa',
        'Marion', 'Agathe', 'Anna', 'Lucie', 'Eva', 'Morgane',
        'Noémie', 'Alicia', 'Romane', 'Maëlys', 'Lola', 'Zoé',
        'Léonie', 'Lisa', 'Coralie', 'Ambre', 'Mélissa', 'Solène',
        'Gaëlle', 'Laure', 'Fatima', 'Aïcha', 'Zineb', 'Samira',
        'Leïla', 'Nadia', 'Hawa', 'Mariam', 'Elsa', 'Margot',
        'Clémence', 'Lina',
    ];

    /** @var list<string> */
    private const NOMS = [
        'Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit',
        'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Lefebvre', 'Michel',
        'Garcia', 'Bertrand', 'Roux', 'Vincent', 'Fournier', 'Morel',
        'Girard', 'André', 'Mercier', 'Dupont', 'Lambert', 'Bonnet',
        'Legrand', 'Garnier', 'Faure', 'Rousseau', 'Blanc', 'Guérin',
        'Muller', 'Henry', 'Perrin', 'Morin', 'Gauthier', 'Dumont',
        'Fontaine', 'Chevalier', 'Robin', 'Masson', 'Nguyen', 'Boyer',
        'Lemaire', 'Duval', 'Joly', 'Renault', 'Brun', 'Da Silva',
        'Diallo', 'Benali', 'Boucher', 'Fleury', 'Leclercq', 'Picard',
        'Marchand', 'Barbier', 'Perez', 'Dufour', 'Baron', 'Lemoine',
        'Berger', 'Renard', 'Giraud', 'Lacroix', 'Breton', 'Hamon',
    ];

    /** @var array<string, string> ville => code_postal */
    private const VILLES_78 = [
        'Versailles' => '78000', 'Saint-Germain-en-Laye' => '78100',
        'Le Vésinet' => '78110', 'Rambouillet' => '78120',
        'Vélizy-Villacoublay' => '78140', 'Le Chesnay-Rocquencourt' => '78150',
        'Marly-le-Roi' => '78160', 'Montigny-le-Bretonneux' => '78180',
        'Mantes-la-Jolie' => '78200', 'Poissy' => '78300',
        'Plaisir' => '78370', 'Chatou' => '78400',
        'Sartrouville' => '78500', 'Maisons-Laffitte' => '78600',
        'Conflans-Sainte-Honorine' => '78700', 'Houilles' => '78800',
        'Feucherolles' => '78810', 'Élancourt' => '78990',
        'Guyancourt' => '78280', 'Louveciennes' => '78430',
    ];

    /** @var list<string> */
    private const PROVIDERS = ['gmail.com', 'orange.fr', 'free.fr', 'sfr.fr', 'outlook.fr', 'yahoo.fr', 'laposte.net'];

    /** @var list<string> */
    private const RUES = [
        'rue de la République', 'avenue de Paris', 'rue Victor Hugo',
        'rue des Écoles', 'rue de la Mairie', 'rue Jean Jaurès',
        'rue Pasteur', 'rue de la Gare', 'rue du Commerce',
        'rue des Lilas', 'rue du Parc', 'rue de la Fontaine',
        'boulevard Voltaire', 'rue Pierre Curie', 'allée des Tilleuls',
        'rue du Château', 'avenue Jean Moulin', 'rue des Merisiers',
        'rue du Moulin', 'rue de l\'Église', 'rue Émile Zola',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Cette commande ne peut pas être exécutée en production.');

            return self::FAILURE;
        }

        $records = ParticipantDonneesMedicales::with('participant.tiers')->get();

        if ($records->isEmpty()) {
            $this->info('Aucune donnée médicale à anonymiser.');

            return self::SUCCESS;
        }

        // Charger les colonnes brutes pour détecter les NULL (fonctionne même
        // si l'APP_KEY est différente de celle qui a chiffré les données)
        $rawRows = DB::table('participant_donnees_medicales')->get()->keyBy('id');

        // Détecter si le déchiffrement fonctionne (même APP_KEY que la source)
        $canDecrypt = false;
        $sampleRaw = $rawRows->first(fn ($r) => $r->sexe !== null);
        if ($sampleRaw) {
            try {
                ParticipantDonneesMedicales::find($sampleRaw->id)->sexe;
                $canDecrypt = true;
            } catch (DecryptException) {
                $this->warn('APP_KEY différente de la source — sexe attribué aléatoirement.');
            }
        }

        $bar = $this->output->createProgressBar($records->count());
        $bar->start();

        $tiersUpdated = [];

        foreach ($records as $med) {
            $raw = $rawRows[$med->id];

            // Déterminer le sexe (déchiffré si possible, sinon aléatoire)
            $sexe = null;
            if ($raw->sexe !== null) {
                if ($canDecrypt) {
                    $sexe = $med->sexe;
                } else {
                    $sexe = ['M', 'F'][random_int(0, 1)];
                }
            }

            // Détecter les champs non-null depuis les colonnes brutes
            $hadDateNaissance = $raw->date_naissance !== null;
            $hadPoids = $raw->poids !== null;
            $hadTaille = $raw->taille !== null;
            $hadMedecin = $raw->medecin_nom !== null;
            $hadMedecinEmail = $raw->medecin_email !== null;
            $hadMedecinAdresse = $raw->medecin_adresse !== null;
            $hadMedecinCp = $raw->medecin_code_postal !== null;
            $hadMedecinVille = $raw->medecin_ville !== null;
            $hadTherapeute = $raw->therapeute_nom !== null;
            $hadTherapeuteEmail = $raw->therapeute_email !== null;
            $hadTherapeuteAdresse = $raw->therapeute_adresse !== null;
            $hadTherapeuteCp = $raw->therapeute_code_postal !== null;
            $hadTherapeuteVille = $raw->therapeute_ville !== null;

            $prenoms = ($sexe === 'F') ? self::PRENOMS_F : self::PRENOMS_M;

            // ── Données médicales fictives (chiffrées automatiquement par le cast) ──
            $med->date_naissance = $hadDateNaissance
                ? sprintf('%02d/%02d/%04d', random_int(1, 28), random_int(1, 12), random_int(1950, 2010))
                : null;
            $med->sexe = $sexe; // préservé
            $med->poids = $hadPoids ? (string) random_int(45, 100) : null;
            $med->taille = $hadTaille ? (string) random_int(150, 195) : null;
            $med->notes = null;

            // Médecin
            if ($hadMedecin) {
                $med->medecin_nom = self::pick(self::NOMS);
                $med->medecin_prenom = self::pick(self::PRENOMS_M);
                $med->medecin_telephone = self::phone();
                $med->medecin_email = $hadMedecinEmail ? self::email($med->medecin_prenom, $med->medecin_nom) : null;
                $med->medecin_adresse = $hadMedecinAdresse ? self::adresse() : null;
                [$ville, $cp] = self::ville();
                $med->medecin_code_postal = $hadMedecinCp ? $cp : null;
                $med->medecin_ville = $hadMedecinVille ? $ville : null;
            }

            // Thérapeute
            if ($hadTherapeute) {
                $med->therapeute_nom = self::pick(self::NOMS);
                $med->therapeute_prenom = self::pick(self::PRENOMS_F);
                $med->therapeute_telephone = self::phone();
                $med->therapeute_email = $hadTherapeuteEmail ? self::email($med->therapeute_prenom, $med->therapeute_nom) : null;
                $med->therapeute_adresse = $hadTherapeuteAdresse ? self::adresse() : null;
                [$ville, $cp] = self::ville();
                $med->therapeute_code_postal = $hadTherapeuteCp ? $cp : null;
                $med->therapeute_ville = $hadTherapeuteVille ? $ville : null;
            }

            $med->save();

            // ── Corriger le prénom du tiers lié selon le sexe ──
            $tiers = $med->participant?->tiers;
            if ($tiers && $sexe && ! isset($tiersUpdated[$tiers->id])) {
                $prenom = self::pick($prenoms);
                $updates = ['prenom' => $tiers->prenom !== null ? $prenom : null];

                if ($tiers->email !== null) {
                    $updates['email'] = self::email($prenom, $tiers->nom);
                }
                if ($tiers->helloasso_prenom !== null) {
                    $updates['helloasso_prenom'] = $prenom;
                }

                DB::table('tiers')->where('id', $tiers->id)->update($updates);
                $tiersUpdated[$tiers->id] = true;
            }

            // ── Corriger adresse_par_prenom du participant ──
            $participant = $med->participant;
            if ($participant && $participant->adresse_par_prenom !== null) {
                $participant->update([
                    'adresse_par_prenom' => self::pick($prenoms),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info(sprintf(
            'Anonymisation terminée : %d fiches médicales, %d tiers corrigés (sexe).',
            $records->count(),
            count($tiersUpdated),
        ));

        return self::SUCCESS;
    }

    /** @param list<string> $array */
    private static function pick(array $array): string
    {
        return $array[array_rand($array)];
    }

    private static function phone(): string
    {
        return '06' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private static function email(string $prenom, string $nom): string
    {
        $slug = fn (string $s): string => strtolower(str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ô', 'î', 'ï', 'ù', 'û', 'ü', 'ç', 'æ', 'œ', ' ', "'"],
            ['e', 'e', 'e', 'e', 'a', 'a', 'o', 'i', 'i', 'u', 'u', 'u', 'c', 'ae', 'oe', '-', ''],
            $s,
        ));

        return $slug($prenom) . '.' . $slug($nom) . '@' . self::pick(self::PROVIDERS);
    }

    private static function adresse(): string
    {
        return random_int(1, 120) . ' ' . self::pick(self::RUES);
    }

    /** @return array{string, string} [ville, code_postal] */
    private static function ville(): array
    {
        $ville = array_rand(self::VILLES_78);

        return [$ville, self::VILLES_78[$ville]];
    }
}
