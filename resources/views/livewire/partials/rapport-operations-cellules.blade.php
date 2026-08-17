{{--
    Cellules de données d'une ligne du rapport par opérations, portée « all ».

    Variables attendues :
      $vMontant            float   total de la ligne
      $vSeances            array   [numero => float]
      $vOperations         array   [operation_id => float]
      $vSeanceOperations   array   [numero => [operation_id => float]]
      $cellStyle           string  style des cellules de détail
      $totalStyle          string  style de la cellule de total

    Héritées du parent : $combinedMode, $parSeances, $parOperations, $seances,
    $operationNames, $seancesParOperation.
--}}
@php
    $fmt = fn (float $v): string => $v > 0 ? number_format($v, 2, ',', ' ').' €' : '—';
@endphp

@if ($combinedMode)
    @foreach ($operationNames as $opId => $opNom)
        @foreach ($seancesParOperation[$opId] ?? [] as $s)
            <td class="text-end" style="{{ $cellStyle }}">{{ $fmt((float) ($vSeanceOperations[$s][$opId] ?? 0)) }}</td>
        @endforeach
        <td class="text-end" style="{{ $cellStyle }}border-left:1px solid #e5e5e5;">{{ $fmt((float) ($vOperations[$opId] ?? 0)) }}</td>
    @endforeach
    <td class="text-end fw-bold" style="{{ $totalStyle }}">{{ number_format($vMontant, 2, ',', ' ') }} &euro;</td>
@elseif ($parSeances)
    @foreach ($seances as $s)
        <td class="text-end" style="{{ $cellStyle }}">{{ $fmt((float) ($vSeances[$s] ?? 0)) }}</td>
    @endforeach
    <td class="text-end fw-bold" style="{{ $totalStyle }}">{{ number_format($vMontant, 2, ',', ' ') }} &euro;</td>
@elseif ($parOperations)
    @foreach ($operationNames as $opId => $opNom)
        <td class="text-end" style="{{ $cellStyle }}">{{ $fmt((float) ($vOperations[$opId] ?? 0)) }}</td>
    @endforeach
    <td class="text-end fw-bold" style="{{ $totalStyle }}">{{ number_format($vMontant, 2, ',', ' ') }} &euro;</td>
@else
    <td class="text-end" style="{{ $totalStyle }}">{{ number_format($vMontant, 2, ',', ' ') }} &euro;</td>
@endif
