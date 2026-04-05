@props(['tiersId'])

@if($tiersId)
<button type="button"
        class="btn btn-link p-0 ms-1 text-info"
        style="font-size:.75rem;line-height:1;vertical-align:middle"
        title="Vue 360°"
        x-data
        @click.stop="$dispatch('open-tiers-quick-view', { tiersId: {{ $tiersId }}, anchorRect: JSON.parse(JSON.stringify($el.getBoundingClientRect())) })">
    <i class="bi bi-info-circle"></i>
</button>
@endif
