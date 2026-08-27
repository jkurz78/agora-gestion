@extends('pdf.rapport-layout')

@section('styles')
    .bilan-banner { background: #fff3cd; border: 1px solid #e5c867; color: #6a4b00; font-weight: 700; padding: 7px 10px; margin-bottom: 10px; font-size: 12px; }
    .bilan-warning { background: #f8d7da; border: 1px solid #df9da5; color: #842029; padding: 7px 10px; margin-bottom: 10px; font-size: 11px; }
    .bilan-section td { background: #3d5473; color: #fff; border-bottom: none; font-size: 13px; font-weight: 700; padding: 6px 8px; }
    .bilan-table th { background: #dce6f0; color: #1e3a5f; border-bottom: 1px solid #b8ccdf; font-size: 10px; padding: 5px 7px; }
    .bilan-table td { font-size: 10px; padding: 5px 7px; }
    .bilan-table .bilan-rubrique { color: #1e3a5f; font-weight: 600; }
    .bilan-total td { background: #5a7fa8; color: #fff; border-bottom: none; font-size: 11px; font-weight: 700; padding: 6px 7px; }
@endsection

@section('content')
    @php
        $compareN1 = $compareN1 ?? true;
        $fmt = function (int $centimes): string {
            return $centimes === 0 ? '—' : number_format($centimes / 100, 2, ',', ' ').' €';
        };
        $ecartN = (int) $bilan['ecart_actif_passif']['n_centimes'];
        $ecartN1 = (int) $bilan['ecart_actif_passif']['n_1_centimes'];
    @endphp

    @if ($bilan['provisoire'])
        <div class="bilan-banner">Bilan provisoire avant clôture</div>
    @endif

    @if ($ecartN !== 0 || ($compareN1 && $ecartN1 !== 0))
        <div class="bilan-warning">
            <strong>Écart actif/passif :</strong> {{ $fmt($ecartN) }} pour {{ $bilan['label_n'] }}
            @if ($compareN1 && $ecartN1 !== 0)
                · {{ $fmt($ecartN1) }} pour {{ $bilan['label_n_1'] }}
            @endif
        </div>
    @endif

    <table class="data-table bilan-table" style="margin-bottom:12px;">
        <thead>
            <tr class="bilan-section">
                <td colspan="{{ $compareN1 ? 5 : 4 }}">ACTIF</td>
            </tr>
            <tr>
                <th>Rubrique ANC</th>
                <th class="text-right" style="width:105px;">Brut {{ $bilan['label_n'] }}</th>
                <th class="text-right" style="width:125px;">Amortissements<br>et provisions</th>
                <th class="text-right" style="width:105px;">Net {{ $bilan['label_n'] }}</th>
                @if ($compareN1)
                    <th class="text-right" style="width:105px;">Net {{ $bilan['label_n_1'] }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($bilan['actif'] as $rubrique)
                <tr>
                    <td class="bilan-rubrique">{{ $rubrique['libelle'] }}</td>
                    <td class="text-right">{{ $fmt((int) $rubrique['brut_n_centimes']) }}</td>
                    <td class="text-right">{{ $fmt((int) $rubrique['amortissements_provisions_n_centimes']) }}</td>
                    <td class="text-right">{{ $fmt((int) $rubrique['net_n_centimes']) }}</td>
                    @if ($compareN1)
                        <td class="text-right">{{ $fmt((int) $rubrique['net_n_1_centimes']) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $compareN1 ? 5 : 4 }}" class="text-center text-muted">Aucun actif à présenter.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="bilan-total">
                <td>TOTAL ACTIF</td>
                <td class="text-right">{{ $fmt((int) $bilan['totaux']['actif_n_brut_centimes']) }}</td>
                <td class="text-right">{{ $fmt((int) $bilan['totaux']['actif_n_amortissements_provisions_centimes']) }}</td>
                <td class="text-right">{{ $fmt((int) $bilan['totaux']['actif_n_net_centimes']) }}</td>
                @if ($compareN1)
                    <td class="text-right">{{ $fmt((int) $bilan['totaux']['actif_n_1_net_centimes']) }}</td>
                @endif
            </tr>
        </tfoot>
    </table>

    <table class="data-table bilan-table" style="page-break-inside: avoid;">
        <thead>
            <tr class="bilan-section">
                <td colspan="{{ $compareN1 ? 3 : 2 }}">PASSIF</td>
            </tr>
            <tr>
                <th>Rubrique ANC</th>
                <th class="text-right" style="width:125px;">{{ $bilan['label_n'] }}</th>
                @if ($compareN1)
                    <th class="text-right" style="width:125px;">{{ $bilan['label_n_1'] }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($bilan['passif'] as $rubrique)
                <tr>
                    <td class="bilan-rubrique">{{ $rubrique['libelle'] }}</td>
                    <td class="text-right">{{ $fmt((int) $rubrique['montant_n_centimes']) }}</td>
                    @if ($compareN1)
                        <td class="text-right">{{ $fmt((int) $rubrique['montant_n_1_centimes']) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $compareN1 ? 3 : 2 }}" class="text-center text-muted">Aucun passif à présenter.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr class="bilan-total">
                <td>TOTAL PASSIF</td>
                <td class="text-right">{{ $fmt((int) $bilan['totaux']['passif_n_centimes']) }}</td>
                @if ($compareN1)
                    <td class="text-right">{{ $fmt((int) $bilan['totaux']['passif_n_1_centimes']) }}</td>
                @endif
            </tr>
        </tfoot>
    </table>
@endsection
