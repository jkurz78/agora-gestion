<div>
    {{-- Header with exercice selector --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="subtotalToggle">
                <label class="form-check-label small" for="subtotalToggle">Sous-totaux</label>
            </div>
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Data carrier + pivot container --}}
    <div id="pivot-wrapper" data-pivot='@json($pivotData)' data-view="{{ $mode }}">
        <div id="pivot-output" wire:ignore class="border rounded bg-white p-2"></div>
    </div>

    @script
    <script>
        function renderPivot() {
            var wrapper = document.getElementById('pivot-wrapper');
            var el = document.getElementById('pivot-output');
            var toggle = document.getElementById('subtotalToggle');
            if (!wrapper || !el || typeof jQuery === 'undefined' || typeof jQuery.fn.pivotUI === 'undefined') {
                setTimeout(renderPivot, 100);
                return;
            }

            var data = JSON.parse(wrapper.dataset.pivot || '[]');
            var view = wrapper.dataset.view || 'participants';
            var useSubtotals = toggle && toggle.checked && typeof jQuery.pivotUtilities.subtotal_renderers !== 'undefined';

            var defaults = view === 'participants'
                ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                : { rows: ["Catégorie"], vals: ["Montant"], aggregatorName: "Somme" };

            var config = Object.assign({
                locale: "fr",
                cols: [],
                rendererName: useSubtotals ? "Table With Subtotal" : "Table",
            }, defaults);

            if (useSubtotals) {
                config.dataClass = jQuery.pivotUtilities.SubtotalPivotData;
                config.renderers = jQuery.pivotUtilities.subtotal_renderers;
            }

            jQuery(el).empty().pivotUI(data, config);
        }

        renderPivot();
        document.getElementById('subtotalToggle')?.addEventListener('change', function() {
            renderPivot();
        });
        $wire.$watch('filterExercice', () => setTimeout(renderPivot, 100));
    </script>
    @endscript
</div>
