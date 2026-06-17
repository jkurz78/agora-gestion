<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PartieDoubleGuard;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class VirementInterneService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function create(array $data): VirementInterne
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        return DB::transaction(function () use ($data) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

            $virement = VirementInterne::create($data);

            if (config('compta.use_partie_double')) {
                app(EcritureGenerator::class)->pourVirementInterne($virement);
                PartieDoubleGuard::assertComplete($virement->fresh()->transaction);
            }

            return $virement;
        });
    }

    public function update(VirementInterne $virement, array $data): VirementInterne
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        return DB::transaction(function () use ($virement, $data) {
            $existingTx = Transaction::where('virement_interne_id', $virement->id)->first();
            if ($existingTx !== null) {
                $existingTx->lignes()->forceDelete();
                $existingTx->forceDelete();
            }

            $virement->update($data);
            $virement = $virement->fresh();

            if (config('compta.use_partie_double')) {
                app(EcritureGenerator::class)->pourVirementInterne($virement);
                PartieDoubleGuard::assertComplete($virement->fresh()->transaction);
            }

            return $virement;
        });
    }

    public function delete(VirementInterne $virement): void
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($virement->date))
        );

        if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null) {
            throw new \RuntimeException('Ce virement est pointé dans un rapprochement et ne peut pas être supprimé.');
        }

        if (RemiseBancaire::where('virement_id', $virement->id)->exists()) {
            throw new \RuntimeException('Ce virement est lié à une remise bancaire et ne peut pas être supprimé.');
        }

        DB::transaction(function () use ($virement) {
            $existingTx = Transaction::where('virement_interne_id', $virement->id)->first();
            if ($existingTx !== null) {
                $existingTx->lignes()->forceDelete();
                $existingTx->forceDelete();
            }

            $virement->delete();
        });
    }
}
