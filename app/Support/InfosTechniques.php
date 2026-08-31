<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Collecte les informations techniques affichées dans Paramètres → Système.
 *
 * Aucune version n'est codée en dur : elles viennent de la plateforme, de
 * Composer et du serveur de base de données. Elles restent donc justes après
 * chaque montée de version, sans intervention.
 */
final class InfosTechniques
{
    /** @var list<string> */
    private const EXTENSIONS_CRITIQUES = ['imagick', 'gd', 'intl', 'bcmath', 'zip', 'pdo_mysql'];

    /**
     * @return array{
     *     agoragestion: array<string, string>,
     *     socle: array<string, string>,
     *     serveur: array<string, string>,
     *     extensions: array<string, bool>
     * }
     */
    public function collecter(): array
    {
        return [
            'agoragestion' => [
                'Version' => (string) config('version.tag', 'dev'),
                'Publiée le' => (string) config('version.date', ''),
                'Environnement' => app()->environment(),
            ],
            'socle' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Livewire' => $this->versionPaquet('livewire/livewire'),
                'Base de données' => $this->baseDeDonnees(),
            ],
            'serveur' => [
                'Mémoire maximale' => (string) ini_get('memory_limit'),
                'Taille maximale d’un fichier' => (string) ini_get('upload_max_filesize'),
                'Taille maximale d’un envoi' => (string) ini_get('post_max_size'),
                'Durée maximale d’exécution' => ini_get('max_execution_time').' s',
            ],
            'extensions' => $this->extensions(),
        ];
    }

    /**
     * Le serveur et sa version, lus sur la connexion réellement ouverte plutôt
     * que dans la configuration : c'est ce qui répond en production.
     */
    private function baseDeDonnees(): string
    {
        $nom = match (DB::connection()->getDriverName()) {
            'mysql' => 'MySQL',
            'mariadb' => 'MariaDB',
            'sqlite' => 'SQLite',
            'pgsql' => 'PostgreSQL',
            default => ucfirst(DB::connection()->getDriverName()),
        };

        try {
            $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            // Un pilote peut ne pas exposer l'attribut : le nom seul reste utile.
            return $nom;
        }

        return trim($nom.' '.$version);
    }

    private function versionPaquet(string $paquet): string
    {
        try {
            return (string) InstalledVersions::getPrettyVersion($paquet);
        } catch (Throwable) {
            return 'inconnue';
        }
    }

    /** @return array<string, bool> */
    private function extensions(): array
    {
        $etat = [];

        foreach (self::EXTENSIONS_CRITIQUES as $extension) {
            $etat[$extension] = extension_loaded($extension);
        }

        return $etat;
    }
}
