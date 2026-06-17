<?php

declare(strict_types=1);

namespace App\Services\Rapports;

final class ProjectionMatrix
{
    /** @var array<int, array<int, array<int, array<int, float>>>> cells[scId][tiersId][seance][opId] */
    private array $cells = [];

    /** @var array<int, int> scId => catId */
    private array $scToCat = [];

    /** @var array<string, mixed> */
    private array $cache = [];

    public function setScCategory(int $scId, int $catId): void
    {
        $this->scToCat[$scId] = $catId;
    }

    public function set(int $scId, int $tiersId, int $seance, int $opId, float $value): void
    {
        $this->cells[$scId][$tiersId][$seance][$opId] = $value;
        $this->cache = [];
    }

    public function isEmpty(): bool
    {
        return $this->cells === [];
    }

    // ── Agrégations ──────────────────────────────────────────────────────────

    public function total(): float
    {
        return $this->cache[__FUNCTION__] ??= $this->computeTotal();
    }

    /** @return array<int, float> scId => float */
    public function bySc(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeBySc();
    }

    /** @return array<int, float> catId => float */
    public function byCat(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByCat();
    }

    /** @return array<int, array<int, float>> scId => [seance => float] */
    public function byScSeance(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByScSeance();
    }

    /** @return array<int, array<int, float>> scId => [opId => float] */
    public function byScOp(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByScOp();
    }

    /** @return array<int, array<int, float>> catId => [opId => float] */
    public function byCatOp(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByCatOp();
    }

    /** @return array<int, float> opId => float */
    public function byOp(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByOp();
    }

    /** @return array<int, array<int, array<int, float>>> scId => [seance => [opId => float]] */
    public function byScSeanceOp(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeByScSeanceOp();
    }

    /** @return array<int, float> tiersId => float */
    public function byScTiers(int $scId): array
    {
        $key = __FUNCTION__.'_'.$scId;

        return $this->cache[$key] ??= $this->computeByScTiers($scId);
    }

    /** @return array<int, array<int, float>> tiersId => [seance => float] */
    public function byScTiersSeance(int $scId): array
    {
        $key = __FUNCTION__.'_'.$scId;

        return $this->cache[$key] ??= $this->computeByScTiersSeance($scId);
    }

    /** @return array<int, array<int, float>> tiersId => [opId => float] */
    public function byScTiersOp(int $scId): array
    {
        $key = __FUNCTION__.'_'.$scId;

        return $this->cache[$key] ??= $this->computeByScTiersOp($scId);
    }

    /** @return array<int, array<int, array<int, float>>> tiersId => [seance => [opId => float]] */
    public function byScTiersSeanceOp(int $scId): array
    {
        $key = __FUNCTION__.'_'.$scId;

        return $this->cache[$key] ??= $this->computeByScTiersSeanceOp($scId);
    }

    /** @return array<int, array<int, float>> seance => [opId => float] */
    public function bySeanceOp(): array
    {
        return $this->cache[__FUNCTION__] ??= $this->computeBySeanceOp();
    }

    // ── Compute helpers ──────────────────────────────────────────────────────

    private function computeTotal(): float
    {
        $sum = 0.0;
        foreach ($this->cells as $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $ops) {
                    foreach ($ops as $val) {
                        $sum += $val;
                    }
                }
            }
        }

        return $sum;
    }

    /** @return array<int, float> */
    private function computeBySc(): array
    {
        $result = [];
        foreach ($this->cells as $scId => $tiers) {
            $sum = 0.0;
            foreach ($tiers as $seances) {
                foreach ($seances as $ops) {
                    foreach ($ops as $val) {
                        $sum += $val;
                    }
                }
            }
            $result[$scId] = $sum;
        }

        return $result;
    }

    /** @return array<int, float> */
    private function computeByCat(): array
    {
        $result = [];
        foreach ($this->bySc() as $scId => $val) {
            $catId = $this->scToCat[$scId] ?? 0;
            $result[$catId] = ($result[$catId] ?? 0.0) + $val;
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeByScSeance(): array
    {
        $result = [];
        foreach ($this->cells as $scId => $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $seance => $ops) {
                    foreach ($ops as $val) {
                        $result[$scId][$seance] = ($result[$scId][$seance] ?? 0.0) + $val;
                    }
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeByScOp(): array
    {
        $result = [];
        foreach ($this->cells as $scId => $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $ops) {
                    foreach ($ops as $opId => $val) {
                        $result[$scId][$opId] = ($result[$scId][$opId] ?? 0.0) + $val;
                    }
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeByCatOp(): array
    {
        $result = [];
        foreach ($this->byScOp() as $scId => $ops) {
            $catId = $this->scToCat[$scId] ?? 0;
            foreach ($ops as $opId => $val) {
                $result[$catId][$opId] = ($result[$catId][$opId] ?? 0.0) + $val;
            }
        }

        return $result;
    }

    /** @return array<int, float> */
    private function computeByOp(): array
    {
        $result = [];
        foreach ($this->cells as $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $ops) {
                    foreach ($ops as $opId => $val) {
                        $result[$opId] = ($result[$opId] ?? 0.0) + $val;
                    }
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<int, array<int, float>>> */
    private function computeByScSeanceOp(): array
    {
        $result = [];
        foreach ($this->cells as $scId => $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $seance => $ops) {
                    foreach ($ops as $opId => $val) {
                        $result[$scId][$seance][$opId] = ($result[$scId][$seance][$opId] ?? 0.0) + $val;
                    }
                }
            }
        }

        return $result;
    }

    /** @return array<int, float> */
    private function computeByScTiers(int $scId): array
    {
        $result = [];
        foreach ($this->cells[$scId] ?? [] as $tiersId => $seances) {
            $sum = 0.0;
            foreach ($seances as $ops) {
                foreach ($ops as $val) {
                    $sum += $val;
                }
            }
            $result[$tiersId] = $sum;
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeByScTiersSeance(int $scId): array
    {
        $result = [];
        foreach ($this->cells[$scId] ?? [] as $tiersId => $seances) {
            foreach ($seances as $seance => $ops) {
                $sum = 0.0;
                foreach ($ops as $val) {
                    $sum += $val;
                }
                $result[$tiersId][$seance] = $sum;
            }
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeByScTiersOp(int $scId): array
    {
        $result = [];
        foreach ($this->cells[$scId] ?? [] as $tiersId => $seances) {
            foreach ($seances as $ops) {
                foreach ($ops as $opId => $val) {
                    $result[$tiersId][$opId] = ($result[$tiersId][$opId] ?? 0.0) + $val;
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<int, array<int, float>>> */
    private function computeByScTiersSeanceOp(int $scId): array
    {
        $result = [];
        foreach ($this->cells[$scId] ?? [] as $tiersId => $seances) {
            foreach ($seances as $seance => $ops) {
                foreach ($ops as $opId => $val) {
                    $result[$tiersId][$seance][$opId] = ($result[$tiersId][$seance][$opId] ?? 0.0) + $val;
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<int, float>> */
    private function computeBySeanceOp(): array
    {
        $result = [];
        foreach ($this->cells as $tiers) {
            foreach ($tiers as $seances) {
                foreach ($seances as $seance => $ops) {
                    foreach ($ops as $opId => $val) {
                        $result[$seance][$opId] = ($result[$seance][$opId] ?? 0.0) + $val;
                    }
                }
            }
        }

        return $result;
    }
}
