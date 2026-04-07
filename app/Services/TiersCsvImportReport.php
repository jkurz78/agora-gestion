<?php

declare(strict_types=1);

namespace App\Services;

final class TiersCsvImportReport
{
    /**
     * @param  array<int, array{line: int, entreprise: ?string, nom: ?string, prenom: ?string, decision: string}>  $lines
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $enriched = 0,
        public readonly int $resolvedMerge = 0,
        public readonly int $resolvedNew = 0,
        public readonly array $lines = [],
    ) {}

    public function total(): int
    {
        return $this->created + $this->enriched + $this->resolvedMerge + $this->resolvedNew;
    }

    /**
     * Generate a text report for download.
     */
    public function toText(string $filename): string
    {
        $text = 'Import tiers du '.now()->format('d/m/Y')." — fichier: {$filename}\n";
        $text .= str_repeat('=', 60)."\n";
        $text .= "Résumé : {$this->total()} lignes traitées — {$this->created} créés, {$this->enriched} enrichis auto, {$this->resolvedMerge} résolus par fusion, {$this->resolvedNew} créés manuellement\n\n";

        $text .= sprintf("%-6s | %-20s | %-15s | %-10s | %s\n", 'Ligne', 'Entreprise', 'Nom', 'Prénom', 'Décision');
        $text .= str_repeat('-', 100)."\n";

        foreach ($this->lines as $line) {
            $text .= sprintf(
                "%-6d | %-20s | %-15s | %-10s | %s\n",
                $line['line'],
                mb_substr($line['entreprise'] ?? '', 0, 20),
                mb_substr($line['nom'] ?? '', 0, 15),
                mb_substr($line['prenom'] ?? '', 0, 10),
                $line['decision'],
            );
        }

        return $text;
    }
}
