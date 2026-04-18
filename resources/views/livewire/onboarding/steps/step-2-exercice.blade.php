<h3>2. Exercice comptable</h3>
<p class="text-muted">Indiquez le mois de début de votre exercice comptable. Par défaut, un exercice commence le 1<sup>er</sup> septembre en France pour les associations sportives et culturelles ; la plupart des autres utilisent janvier.</p>

<form wire:submit="saveStep2">
    <div class="mb-3">
        <label class="form-label">Mois de début d'exercice</label>
        <select wire:model="exerciceMoisDebut" class="form-select @error('exerciceMoisDebut') is-invalid @enderror">
            @foreach (['1' => 'Janvier','2' => 'Février','3' => 'Mars','4' => 'Avril','5' => 'Mai','6' => 'Juin','7' => 'Juillet','8' => 'Août','9' => 'Septembre','10' => 'Octobre','11' => 'Novembre','12' => 'Décembre'] as $v => $label)
                <option value="{{ $v }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('exerciceMoisDebut') <div class="invalid-feedback">{{ $message }}</div> @enderror
        <small class="form-text text-muted">Un exercice de 12 mois commence au 1<sup>er</sup> du mois choisi et se termine le dernier jour du mois précédent, l'année suivante.</small>
    </div>

    <button type="button" wire:click="goToStep(1)" class="btn btn-link">← Retour</button>
    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Valider et continuer</button>
</form>
