<?php

declare(strict_types=1);

namespace App\Services;

final class ParticipantXlsxImportReport
{
    /**
     * @param  array<int, array{line: int, nom: ?string, prenom: ?string, decision: string}>  $lines
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $linked = 0,
        public readonly int $skipped = 0,
        public readonly array $lines = [],
    ) {}

    public function total(): int
    {
        return $this->created + $this->linked + $this->skipped;
    }

    public function toText(string $filename): string
    {
        $text = 'Import participants du '.now()->format('d/m/Y')." — fichier: {$filename}\n";
        $text .= str_repeat('=', 60)."\n";
        $text .= "Résumé : {$this->total()} lignes traitées — {$this->created} créés (nouveau tiers), {$this->linked} liés (tiers existant), {$this->skipped} ignorés (déjà participant)\n\n";

        $text .= sprintf("%-6s | %-20s | %-15s | %s\n", 'Ligne', 'Nom', 'Prénom', 'Décision');
        $text .= str_repeat('-', 80)."\n";

        foreach ($this->lines as $line) {
            $text .= sprintf(
                "%-6d | %-20s | %-15s | %s\n",
                $line['line'],
                mb_substr($line['nom'] ?? '', 0, 20),
                mb_substr($line['prenom'] ?? '', 0, 15),
                $line['decision'],
            );
        }

        return $text;
    }
}
