{{--
    Sélecteur de durée d'amortissement, partagé par le formulaire de création
    et le formulaire d'édition (App\Livewire\Immobilisations\Concerns\WithDureeSelector).
    Attend une variable $dureesUsuelles [mois => libellé] dans le scope appelant.
--}}
<label class="form-label">Durée d'amortissement</label>
<select class="form-select @error('duree_mois') is-invalid @enderror" wire:model.live="dureeChoix">
    @foreach ($dureesUsuelles as $mois => $label)
        <option value="{{ $mois }}">{{ $label }}</option>
    @endforeach
    <option value="autre">Autre durée…</option>
</select>
@if ($dureeChoix === 'autre')
    <div class="input-group mt-2">
        <input type="number" min="1" max="600" class="form-control @error('duree_mois') is-invalid @enderror"
               wire:model="duree_mois">
        <span class="input-group-text">mois</span>
    </div>
@endif
@error('duree_mois') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
