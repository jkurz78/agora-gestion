<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RecuFiscalException;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

final class RecuFiscalService
{
    public function validerEligibilite(TransactionLigne $ligne): void
    {
        $asso = Association::findOrFail(TenantContext::currentId());

        if (! $asso->eligible_recu_fiscal) {
            throw RecuFiscalException::associationNonEligible();
        }

        if (empty($asso->signataire_nom) || empty($asso->signataire_qualite)) {
            throw RecuFiscalException::signataireManquant();
        }

        if (! $ligne->sousCategorie) {
            throw RecuFiscalException::sansSousCategorie();
        }

        $transaction = $ligne->transaction;

        if (! $transaction->statut_reglement->isEncaisse()) {
            throw RecuFiscalException::transactionNonEncaissee();
        }

        $tiers = $transaction->tiers;
        $champsObligatoires = [
            'adresse_ligne1' => 'rue',
            'code_postal' => 'code postal',
            'ville' => 'ville',
        ];

        foreach ($champsObligatoires as $champ => $libelle) {
            if (empty($tiers->{$champ})) {
                throw RecuFiscalException::adresseDonateurManquante($libelle);
            }
        }
    }

    private function allouerNumero(int $annee): string
    {
        return DB::transaction(function () use ($annee) {
            $associationId = TenantContext::currentId();

            $dernier = RecuFiscalEmis::query()
                ->where('association_id', $associationId)
                ->where('annee_civile', $annee)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $sequence = 1;
            if ($dernier !== null) {
                $parts = explode('-', $dernier->numero);
                $sequence = (int) end($parts) + 1;
            }

            return sprintf('%d-%04d', $annee, $sequence);
        });
    }
}
