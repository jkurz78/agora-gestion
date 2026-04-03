<div>
    @if (! $hasSelection)
        <p>S&eacute;lectionnez au moins une op&eacute;ration</p>
    @else
        @foreach ($charges as $cat)
            <span>{{ $cat['label'] }}</span>
            @foreach ($cat['sous_categories'] as $sc)
                <span>{{ $sc['label'] }}</span>
                @if ($parSeances)
                    <span>{{ number_format($sc['total'], 2, ',', ' ') }}</span>
                @else
                    <span>{{ number_format($sc['montant'], 2, ',', ' ') }}</span>
                @endif
                @if ($parTiers && !empty($sc['tiers']))
                    @foreach ($sc['tiers'] as $t)
                        <span>{{ $t['label'] }}</span>
                    @endforeach
                @endif
            @endforeach
        @endforeach
    @endif
</div>
