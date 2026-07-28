<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Rapports\Concerns\AnalysePeriodeComptable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Livre-journal : les écritures de la période, classées par journal puis par
 * pièce.
 *
 * C'est le livre primaire — le grand livre n'en est qu'un reclassement par
 * compte, la balance qu'une synthèse. Sa valeur propre : chaque pièce apparaît
 * d'un bloc avec toutes ses lignes, débit et crédit face à face, ce qui permet
 * de vérifier qu'une écriture est correctement passée.
 *
 * Contrairement à la balance et au grand livre, la pièce d'à-nouveaux n'y est
 * pas une « ouverture » : le journal des AN est un journal comme un autre, et
 * sa pièce s'y affiche dès lors qu'il est retenu.
 */
final class JournauxBuilder
{
    use AnalysePeriodeComptable;

    /**
     * @param  array<int, string>  $journaux  Codes de journaux retenus ; vide = tous.
     * @return array{date_debut: string, date_fin: string, journaux_retenus: array<int, string>, journaux: list<array<string, mixed>>, totaux: array<string, int>}
     */
    public function journaux(string $dateDebut, string $dateFin, array $journaux = []): array
    {
        $debut = CarbonImmutable::parse($dateDebut)->startOfDay();
        $fin = CarbonImmutable::parse($dateFin)->startOfDay();
        $retenus = $this->normaliserJournaux($journaux);

        $blocs = [];

        foreach ($this->lignesDeLaPeriode($debut, $fin, $retenus) as $ligne) {
            $transaction = $ligne->transaction;
            if ($transaction === null || $ligne->compte === null) {
                continue;
            }

            $codeJournal = $this->journal($transaction) ?? '';
            $blocs[$codeJournal] ??= $this->journalVide($codeJournal);

            $pieceId = (int) $transaction->id;
            $blocs[$codeJournal]['pieces'][$pieceId] ??= $this->pieceVide($transaction);
            $blocs[$codeJournal]['pieces'][$pieceId]['lignes'][] = $this->ligneJournal($ligne);
        }

        $journauxFinalises = collect($blocs)
            ->map(fn (array $bloc): array => $this->finaliserJournal($bloc))
            ->sort(fn (array $a, array $b): int => strcmp($a['code'], $b['code']))
            ->values()
            ->all();

        return [
            'date_debut' => $debut->toDateString(),
            'date_fin' => $fin->toDateString(),
            'journaux_retenus' => $retenus,
            'journaux' => $journauxFinalises,
            'totaux' => $this->totaux($journauxFinalises),
        ];
    }

    /**
     * Écritures de la période. La coupure des à-nouveaux borne la lecture :
     * rien avant la dernière pièce AN n'appartient à l'exercice courant.
     *
     * @param  array<int, string>  $retenus
     * @return Collection<int, TransactionLigne>
     */
    private function lignesDeLaPeriode(
        CarbonImmutable $debut,
        CarbonImmutable $fin,
        array $retenus,
    ): Collection {
        return TransactionLigne::query()
            ->with(['compte', 'tiers', 'transaction'])
            ->whereHas('transaction', function (Builder $query) use ($debut, $fin, $retenus): void {
                $query
                    ->whereDate('date', '>=', $debut->toDateString())
                    ->whereDate('date', '<=', $fin->toDateString());

                if ($retenus !== []) {
                    $query->whereIn('journal', $retenus);
                }
            })
            ->whereHas('compte')
            ->get()
            ->sort(fn (TransactionLigne $a, TransactionLigne $b): int => $this->comparerLignes($a, $b))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function journalVide(string $code): array
    {
        $journal = JournalComptable::tryFrom($code);

        return [
            'code' => $code,
            'libelle' => $journal?->label() ?? ($code !== '' ? $code : 'Sans journal'),
            'pieces' => [],
            'debit_centimes' => 0,
            'credit_centimes' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pieceVide(Transaction $transaction): array
    {
        return [
            'transaction_id' => (int) $transaction->id,
            'date' => $transaction->date->toDateString(),
            'numero_piece' => $transaction->numero_piece,
            'reference' => $transaction->reference,
            'libelle' => $transaction->libelle,
            'mode_paiement' => $this->modePaiement($transaction),
            'justificatif_url' => $this->urlJustificatifTransaction($transaction),
            'lignes' => [],
            'debit_centimes' => 0,
            'credit_centimes' => 0,
            'equilibree' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneJournal(TransactionLigne $ligne): array
    {
        return [
            'ligne_id' => (int) $ligne->id,
            'numero_compte' => (string) ($ligne->compte?->numero_pcg ?? ''),
            'intitule_compte' => (string) ($ligne->compte?->intitule ?? ''),
            'tiers' => $ligne->tiers?->displayName(),
            'libelle' => $ligne->libelle ?: $ligne->transaction?->libelle,
            'lettrage_code' => $ligne->lettrage_code,
            'justificatif_url' => $this->urlJustificatifLigne($ligne),
            'debit_centimes' => $this->centimes($ligne->debit),
            'credit_centimes' => $this->centimes($ligne->credit),
        ];
    }

    /**
     * Totalise chaque pièce puis le journal. Une pièce dont le débit et le
     * crédit diffèrent est signalée : c'est une écriture déséquilibrée, que le
     * journal doit rendre visible plutôt que masquer.
     *
     * @param  array<string, mixed>  $bloc
     * @return array<string, mixed>
     */
    private function finaliserJournal(array $bloc): array
    {
        $pieces = collect($bloc['pieces'])
            ->map(function (array $piece): array {
                $piece['debit_centimes'] = (int) collect($piece['lignes'])->sum('debit_centimes');
                $piece['credit_centimes'] = (int) collect($piece['lignes'])->sum('credit_centimes');
                $piece['equilibree'] = $piece['debit_centimes'] === $piece['credit_centimes'];

                return $piece;
            })
            ->sortBy(fn (array $piece): array => [
                $piece['date'],
                (string) ($piece['numero_piece'] ?? ''),
                $piece['transaction_id'],
            ])
            ->values()
            ->all();

        $bloc['pieces'] = $pieces;
        $bloc['debit_centimes'] = (int) collect($pieces)->sum('debit_centimes');
        $bloc['credit_centimes'] = (int) collect($pieces)->sum('credit_centimes');

        return $bloc;
    }

    /**
     * @param  list<array<string, mixed>>  $journaux
     * @return array<string, int>
     */
    private function totaux(array $journaux): array
    {
        return [
            'debit_centimes' => (int) collect($journaux)->sum('debit_centimes'),
            'credit_centimes' => (int) collect($journaux)->sum('credit_centimes'),
            'nb_pieces' => (int) collect($journaux)->sum(fn (array $j): int => count($j['pieces'])),
        ];
    }

    private function modePaiement(Transaction $transaction): ?string
    {
        $mode = $transaction->mode_paiement;

        if ($mode instanceof ModePaiement) {
            return $mode->label();
        }

        return $mode !== null ? (string) $mode : null;
    }

    private function urlJustificatifLigne(TransactionLigne $ligne): ?string
    {
        return $ligne->piece_jointe_path !== null && $ligne->transaction !== null
            ? route('comptabilite.transactions.piece-jointe-ligne', [
                'transaction' => (int) $ligne->transaction->id,
                'ligne' => (int) $ligne->id,
            ])
            : null;
    }

    private function urlJustificatifTransaction(Transaction $transaction): ?string
    {
        return $transaction->piece_jointe_path !== null
            ? route('transactions.piece-jointe', ['transaction' => (int) $transaction->id])
            : null;
    }

    private function comparerLignes(TransactionLigne $a, TransactionLigne $b): int
    {
        return strcmp(
            (string) ($a->transaction?->date?->toDateString() ?? ''),
            (string) ($b->transaction?->date?->toDateString() ?? ''),
        )
            ?: ((int) ($a->transaction_id ?? 0) <=> (int) ($b->transaction_id ?? 0))
            ?: ((int) $a->id <=> (int) $b->id);
    }

    /**
     * @param  array<int, string>  $journaux
     * @return array<int, string>
     */
    private function normaliserJournaux(array $journaux): array
    {
        return collect($journaux)
            ->map(fn (string $code): string => trim($code))
            ->filter(fn (string $code): bool => JournalComptable::tryFrom($code) !== null)
            ->unique()
            ->values()
            ->all();
    }
}
