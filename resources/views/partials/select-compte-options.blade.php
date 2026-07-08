{{--
    DC-8 — dissolution sous_categories → comptes.

    Rend uniquement le contenu (optgroup/option) d'un <select> de compte de
    ventilation — l'écran appelant garde la main sur la balise <select>
    elle-même (wire:model, option "aucun", classes CSS…), qui varie par écran.

    Variables attendues :
    - $groupes    : Collection<string, array{famille: ?Famille, comptes: Collection<int, Compte>}>
                    (retour de App\Services\Compta\PlanComptableSelecteur::groupesPourType())
    - $selectedId : int|string|null, optionnel — marque l'option correspondante "selected"
                    (redondant avec wire:model, utile en @include hors contexte Livewire)
--}}
@foreach ($groupes as $code => $groupe)
    <optgroup label="{{ $groupe['famille']?->libelle() ?? $code }}">
        @foreach ($groupe['comptes'] as $compte)
            <option value="{{ $compte->id }}"{{ isset($selectedId) && (int) $selectedId === (int) $compte->id ? ' selected' : '' }}>
                {{ $compte->numero_pcg }} — {{ $compte->intitule }}
            </option>
        @endforeach
    </optgroup>
@endforeach
