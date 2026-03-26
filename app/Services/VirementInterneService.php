<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RemiseBancaire;
use App\Models\VirementInterne;
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

            return VirementInterne::create($data);
        });
    }

    public function update(VirementInterne $virement, array $data): VirementInterne
    {
        $this->exerciceService->assertOuvert(
            $this->exerciceService->anneeForDate(CarbonImmutable::parse($data['date']))
        );

        return DB::transaction(function () use ($virement, $data) {
            $virement->update($data);

            return $virement->fresh();
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
            $virement->delete();
        });
    }
}
