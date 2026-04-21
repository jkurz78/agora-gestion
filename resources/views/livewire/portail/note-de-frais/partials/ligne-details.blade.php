@php
    /**
     * @var array{type?: string, libelle?: string|null, cv_fiscaux?: int|null, distance_km?: float|int|null, bareme_eur_km?: float|null} $ligne
     */
    $type = $ligne['type'] ?? 'standard';
    $cv = $ligne['cv_fiscaux'] ?? null;
    $km = $ligne['distance_km'] ?? null;
    $bareme = $ligne['bareme_eur_km'] ?? null;

    $formatNumber = function (float $value, int $maxDecimals = 2): string {
        $formatted = rtrim(rtrim(number_format($value, $maxDecimals, ',', ''), '0'), ',');
        return $formatted === '' ? '0' : $formatted;
    };
@endphp

@if ($type === 'kilometrique')
    <div>
        <span class="badge bg-info text-dark me-1">Km</span>
        <span>{{ $ligne['libelle'] ?? '' }}</span>
    </div>
    @if ($cv !== null && $km !== null && $bareme !== null)
        <small class="text-muted d-block">
            {{ (int) $cv }} CV · {{ $formatNumber((float) $km, 2) }} km · {{ $formatNumber((float) $bareme, 3) }} €/km
        </small>
    @endif
@else
    <span>{{ $ligne['libelle'] ?? '—' }}</span>
@endif
