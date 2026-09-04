@extends('pdf.rapport-layout')

@section('content')
    @php
        // Un null n'est pas un zero : il dit que la grandeur ne parle pas pour ce
        // compte. Le formater en 0,00 € affirmerait « aucune subvention attendue »
        // en face d'une subvention budgetee. `realise` est en revanche toujours un
        // float (voir la docblock de BudgetOperationBuilder) : un vrai zero s'y
        // affiche normalement.
        $fmt = fn (?float $v): string => $v !== null ? number_format($v, 2, ',', ' ').' €' : '—';

        // Aucun ecart ecrit a la main : la meme regle App\Support\ComparaisonBudgetaire
        // que l'ecran et le classeur — sans budget affecte, la comparaison n'a pas
        // de sens et reste vide, meme si le realise est non nul.
        $fmtEcart = fn (?float $budget, float $realise): string => $budget !== null
            ? number_format(\App\Support\ComparaisonBudgetaire::ecart($budget, $realise), 2, ',', ' ').' €'
            : '—';

        $multiOperations = count($operations) > 1;
    @endphp

    @foreach ($operations as $op)
        @if ($multiOperations)
            <table class="data-table" style="margin-bottom:4px;">
                <tr class="cr-section-header">
                    <td>{{ $op['operation_nom'] }}</td>
                </tr>
            </table>
        @endif

        @foreach ([
            ['data' => $op['charges'], 'totaux' => $op['totaux']['charges'], 'label' => 'DÉPENSES'],
            ['data' => $op['produits'], 'totaux' => $op['totaux']['produits'], 'label' => 'RECETTES'],
        ] as $section)
            <table class="data-table" style="margin-bottom:14px;">
                <tbody>
                    <tr class="cr-section-header">
                        <td>{{ $section['label'] }}</td>
                        <td class="text-right" style="width:100px;font-weight:400;font-size:10px;opacity:.85;">Budget affecté</td>
                        <td class="text-right" style="width:100px;font-weight:400;font-size:10px;opacity:.85;">Prévisionnel</td>
                        <td class="text-right" style="width:100px;font-weight:400;font-size:10px;opacity:.85;">Réalisé</td>
                        <td class="text-right" style="width:90px;font-weight:400;font-size:10px;opacity:.85;">Écart</td>
                    </tr>
                    @forelse ($section['data'] as $famille)
                        <tr class="cr-cat">
                            <td>{{ $famille['famille_nom'] }}</td>
                            <td class="text-right">{{ $fmt($famille['budget']) }}</td>
                            <td class="text-right">{{ $fmt($famille['prevision']) }}</td>
                            <td class="text-right">{{ $fmt($famille['realise']) }}</td>
                            <td class="text-right">{{ $fmtEcart($famille['budget'], $famille['realise']) }}</td>
                        </tr>
                        @foreach ($famille['comptes'] as $compte)
                            <tr class="cr-sub">
                                <td style="padding-left:20px;">
                                    {{ $compte['compte_nom'] }}
                                    @if ($compte['hors_dotation'])
                                        <span class="text-muted" style="font-size:10px;">(hors dotation)</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ $fmt($compte['budget']) }}</td>
                                <td class="text-right">{{ $fmt($compte['prevision']) }}</td>
                                <td class="text-right">{{ $fmt($compte['realise']) }}</td>
                                <td class="text-right">{{ $fmtEcart($compte['budget'], $compte['realise']) }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted">Aucun compte.</td>
                        </tr>
                    @endforelse
                    <tr class="cr-total">
                        <td>TOTAL {{ $section['label'] }}</td>
                        <td class="text-right">{{ $fmt($section['totaux']['budget']) }}</td>
                        <td class="text-right">{{ $fmt($section['totaux']['prevision']) }}</td>
                        <td class="text-right">{{ $fmt($section['totaux']['realise']) }}</td>
                        <td class="text-right">{{ $fmtEcart($section['totaux']['budget'], $section['totaux']['realise']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endforeach
    @endforeach
@endsection
