<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

final class BudgetExport implements FromArray, WithHeadings
{
    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     */
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['exercice', 'categorie', 'sous_categorie', 'montant_prevu'];
    }
}
