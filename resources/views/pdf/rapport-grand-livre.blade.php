@extends('pdf.rapport-layout')

@section('styles')
    .gl-meta { margin-bottom: 10px; font-size: 11px; color: #6c757d; }
    .gl-meta strong { color: #3d5473; }
    .gl-account-title {
        background: #3d5473;
        color: #fff;
        padding: 5px 7px;
        font-size: 11px;
        font-weight: 700;
        margin-top: 10px;
    }
    .gl-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .gl-table th {
        background: #dce6f0;
        color: #1e3a5f;
        padding: 4px 6px;
        font-size: 9px;
        text-align: left;
    }
    .gl-table td { padding: 4px 6px; font-size: 9px; border-bottom: 1px solid #eee; }
    .gl-opening td { background: #f2f6fa; font-weight: 700; }
    .gl-total td { background: #5a7fa8; color: #fff; font-weight: 700; border-bottom: none; }
    .gl-num { font-family: monospace; }
    .gl-zero { color: #adb5bd; }
    .text-end { text-align: right; }
    .text-center { text-align: center; }
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

    <div class="gl-meta">
        <strong>Comptes :</strong> {{ $comptes }}
    </div>

    @forelse($grandLivre['comptes'] as $compte)
        <div class="gl-account-title">
            {{ $compte['numero_compte'] }} — {{ $compte['intitule_compte'] }}
            @if(($compte['tiers'] ?? null) !== null)
                · {{ $compte['tiers'] }}
            @endif
        </div>

        <table class="gl-table">
            <thead>
                <tr>
                    <th style="width:60px;">Date</th>
                    <th style="width:55px;">Journal</th>
                    <th style="width:80px;">Pièce</th>
                    @if($afficherModeReglement)
                        <th style="width:70px;">Règlement</th>
                    @endif
                    <th>Libellé</th>
                    <th style="width:50px;" class="text-center">Lettr.</th>
                    <th style="width:70px;" class="text-end">Débit</th>
                    <th style="width:70px;" class="text-end">Crédit</th>
                    <th style="width:75px;" class="text-end">Solde</th>
                </tr>
            </thead>
            <tbody>
                <tr class="gl-opening">
                    <td colspan="{{ $afficherModeReglement ? 7 : 6 }}">Solde ouverture</td>
                    <td></td>
                    <td class="text-end">{{ $fmt((int) $compte['solde_ouverture_centimes']) }}</td>
                </tr>

                @foreach($compte['lignes'] as $ligne)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($ligne['date'])->format('d/m/Y') }}</td>
                        <td>{{ $ligne['journal'] }}</td>
                        <td>{{ $ligne['numero_piece'] ?? $ligne['reference'] ?? '—' }}</td>
                        @if($afficherModeReglement)
                            <td>{{ $ligne['mode_paiement'] ?? '—' }}</td>
                        @endif
                        <td>{{ $ligne['libelle'] }}</td>
                        <td class="text-center gl-num">{{ $ligne['lettrage_code'] ?? '—' }}</td>
                        <td class="text-end">{{ $fmt((int) $ligne['debit_centimes']) }}</td>
                        <td class="text-end">{{ $fmt((int) $ligne['credit_centimes']) }}</td>
                        <td class="text-end">{{ $fmt((int) $ligne['solde_progressif_centimes']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="gl-total">
                    <td colspan="{{ $afficherModeReglement ? 6 : 5 }}">TOTAL MOUVEMENTS</td>
                    <td class="text-end">{{ $fmt((int) $compte['mouvement_debit_centimes']) }}</td>
                    <td class="text-end">{{ $fmt((int) $compte['mouvement_credit_centimes']) }}</td>
                    <td class="text-end">{{ $fmt((int) $compte['solde_fin_centimes']) }}</td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p>Aucun mouvement sur la période et les comptes retenus.</p>
    @endforelse
@endsection
