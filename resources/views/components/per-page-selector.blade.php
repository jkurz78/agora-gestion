@props([
    'paginator'  => null,
    'storageKey' => '',
])

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2 mb-1"
     x-data="{
         key: 'perPage.{{ $storageKey }}',
         init() {
             const saved = localStorage.getItem(this.key);
             if (saved !== null) {
                 this.$refs.select.value = saved;
                 this.$refs.select.dispatchEvent(new Event('change'));
             } else {
                 this.$refs.select.value = String(this.$wire.perPage);
             }
         }
     }">
    <small class="text-muted">
        @if ($paginator && $paginator->total() > 0)
            Affichage <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            sur <strong>{{ $paginator->total() }}</strong>
        @elseif ($paginator)
            Aucun résultat
        @endif
    </small>
    <div class="d-flex align-items-center gap-2">
        <label for="perPage-{{ $storageKey }}" class="form-label mb-0 text-muted small">Lignes par page :</label>
        <select id="perPage-{{ $storageKey }}"
                x-ref="select"
                x-on:change="localStorage.setItem(key, $event.target.value)"
                class="form-select form-select-sm w-auto"
                {{ $attributes->filter(fn($v, $k) => str_starts_with($k, 'wire:')) }}>
            <option value="15">15</option>
            <option value="20">20</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="0">Tous</option>
        </select>
    </div>
</div>
