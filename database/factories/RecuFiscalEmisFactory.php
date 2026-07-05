<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class RecuFiscalEmisFactory extends Factory
{
    protected $model = RecuFiscalEmis::class;

    public function definition(): array
    {
        $annee = (int) now()->format('Y');

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'numero' => $annee.'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'annee_civile' => $annee,
            'tiers_id' => Tiers::factory(),
            'transaction_ligne_id' => null,
            'montant_centimes' => 15000,
            'date_versement' => now()->subDays(30)->toDateString(),
            'mode_versement' => 'cheque',
            'forme_don' => 'numeraire',
            'article_cgi' => 'art_200',
            'pdf_path' => "recus_fiscaux/{$annee}/test.pdf",
            'pdf_hash' => str_repeat('a', 64),
            'emitted_at' => now(),
        ];
    }
}
