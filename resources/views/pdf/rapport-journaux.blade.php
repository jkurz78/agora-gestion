@extends('pdf.rapport-layout')

@section('styles')
    .jx-journal-title {
        background: #3d5473;
        color: #fff;
        padding: 5px 7px;
        font-size: 11px;
        font-weight: 700;
        margin-top: 10px;
    }
    .jx-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .jx-table th {
        background: #dce6f0;
        color: #1e3a5f;
        padding: 4px 6px;
        font-size: 9px;
        text-align: left;
    }
    .jx-table td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #eee; }
    .jx-piece td { background: #f2f6fa; font-weight: 700; }
    .jx-desequilibre td { background: #f8d7da; color: #842029; }
    .jx-total td { background: #5a7fa8; color: #fff; font-weight: 700; border-bottom: none; }
    .jx-general { margin-top: 12px; font-size: 11px; font-weight: 700; color: #1e3a5f; }
    .jx-num { font-family: monospace; }
    .jx-tier { color: #6c757d; font-size: 8px; }
    .jx-zero { color: #adb5bd; }
    .text-end { text-align: right; }
    .text-center { text-align: center; }
    /* Même raison qu'au grand livre : le n° de pièce (15 caractères) se repliait
       sur deux lignes et doublait la hauteur de chaque en-tête de pièce. */
    .jx-nowrap { white-space: nowrap; }
@endsection

@section('content')
    @php
        $fmt = function (int $centimes): string {
            if ($centimes === 0) {
                return '—';
            }

            $signe = $centimes < 0 ? '-' : '';

            return $signe . number_format(abs($centimes) / 100, 2, ',', ' ') . ' €';
        };
        $afficherModeReglement = $afficherModeReglement ?? false;
    @endphp

    @forelse($journal['journaux'] as $bloc)
        <div class="jx-journal-title">
            {{ $bloc['libelle'] }} — {{ count($bloc['pieces']) }} pièce(s)
        </div>

        <table class="jx-table">
            <thead>
                <tr>
                    <th style="width:70px;" class="jx-nowrap">Date</th>
                    <th style="width:105px;" class="jx-nowrap">Pièce</th>
                    <th>Compte / Libellé</th>
                    @if($afficherModeReglement)
                        <th style="width:70px;">Règlement</th>
                    @endif
                    <th style="width:50px;" class="text-center">Lettr.</th>
                    <th style="width:75px;" class="text-end">Débit</th>
                    <th style="width:75px;" class="text-end">Crédit</th>
                </tr>
            </thead>
            <tbody>
                @foreach($bloc['pieces'] as $piece)
                    <tr class="jx-piece {{ $piece['equilibree'] ? '' : 'jx-desequilibre' }}">
                        <td class="jx-nowrap">{{ \Carbon\Carbon::parse($piece['date'])->format('d/m/Y') }}</td>
                        <td class="jx-nowrap">{{ $piece['numero_piece'] ?? $piece['reference'] ?? '—' }}</td>
                        <td>
                            {{ $piece['libelle'] }}
                            @unless($piece['equilibree'])
                                (déséquilibrée)
                            @endunless
                        </td>
                        @if($afficherModeReglement)
                            <td>{{ $piece['mode_paiement'] ?? '—' }}</td>
                        @endif
                        <td></td>
                        <td class="text-end">{{ $fmt((int) $piece['debit_centimes']) }}</td>
                        <td class="text-end">{{ $fmt((int) $piece['credit_centimes']) }}</td>
                    </tr>

                    @foreach($piece['lignes'] as $ligne)
                        <tr>
                            <td></td>
                            <td></td>
                            <td>
                                <span class="jx-num">{{ $ligne['numero_compte'] }}</span>
                                — {{ $ligne['intitule_compte'] }}
                                @if($ligne['tiers'] !== null)
                                    <span class="jx-tier">· {{ $ligne['tiers'] }}</span>
                                @endif
                            </td>
                            @if($afficherModeReglement)
                                <td></td>
                            @endif
                            <td class="text-center jx-num">{{ $ligne['lettrage_code'] ?? '—' }}</td>
                            <td class="text-end">{{ $fmt((int) $ligne['debit_centimes']) }}</td>
                            <td class="text-end">{{ $fmt((int) $ligne['credit_centimes']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
            <tfoot>
                <tr class="jx-total">
                    <td colspan="{{ $afficherModeReglement ? 5 : 4 }}">TOTAL {{ mb_strtoupper($bloc['libelle']) }}</td>
                    <td class="text-end">{{ $fmt((int) $bloc['debit_centimes']) }}</td>
                    <td class="text-end">{{ $fmt((int) $bloc['credit_centimes']) }}</td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p>Aucune écriture sur la période et les journaux retenus.</p>
    @endforelse

    @if($journal['journaux'] !== [])
        <div class="jx-general">
            TOTAL GÉNÉRAL — {{ $journal['totaux']['nb_pieces'] }} pièce(s) :
            débit {{ $fmt((int) $journal['totaux']['debit_centimes']) }} /
            crédit {{ $fmt((int) $journal['totaux']['credit_centimes']) }}
        </div>
    @endif
@endsection
