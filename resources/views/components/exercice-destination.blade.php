{{--
    Mention discrète de l'exercice auquel une date d'écriture sera rattachée.

    Contrepartie du retrait des bornes d'exercice affiché sur les champs de
    date : plus rien n'empêche de saisir une date hors de l'exercice
    affiché — c'est voulu, deux exercices ouverts se chevauchent plusieurs
    mois par an. Mais ça retire aussi le seul mécanisme qui attrapait la
    faute de frappe sur l'année (2025 au lieu de 2026 en janvier). Cette
    mention la rend de nouveau visible, sans bloquer la saisie : elle nomme
    l'exercice de destination plutôt que de dire « attention ».

    Ne rend rien si la date est vide, illisible, ou si son exercice est celui
    actuellement affiché à l'écran (cas normal, rien à signaler).

    Budget requêtes : au plus une (la recherche de l'Exercice pour son statut
    de clôture), et seulement quand l'exercice de la date diffère de celui
    affiché. anneeForDate(), current() et label() ne font aucune requête.
--}}
@props([
    'date' => null,
])

@php
    $exerciceDestinationParsed = null;

    if (filled($date)) {
        try {
            $exerciceDestinationParsed = \Carbon\CarbonImmutable::parse($date);
        } catch (\Throwable) {
            $exerciceDestinationParsed = null;
        }
    }
@endphp

@if ($exerciceDestinationParsed !== null)
    @php
        $exerciceDestinationService = app(\App\Services\ExerciceService::class);
        $exerciceDestinationAnnee = $exerciceDestinationService->anneeForDate($exerciceDestinationParsed);
        $exerciceDestinationAffichee = $exerciceDestinationService->current();
    @endphp
    @if ($exerciceDestinationAnnee !== $exerciceDestinationAffichee)
        @php
            $exerciceDestinationModele = \App\Models\Exercice::where('annee', $exerciceDestinationAnnee)->first();
            $exerciceDestinationCloture = $exerciceDestinationModele?->isCloture() ?? false;
            $exerciceDestinationLabel = $exerciceDestinationService->label($exerciceDestinationAnnee);
        @endphp
        <div class="form-text small mb-0 {{ $exerciceDestinationCloture ? 'text-danger' : 'text-muted' }}">
            @if ($exerciceDestinationCloture)
                <i class="bi bi-exclamation-triangle"></i>
                Cette écriture sera rattachée à l'exercice {{ $exerciceDestinationLabel }}, clôturé : l'enregistrement sera refusé.
            @else
                <i class="bi bi-info-circle"></i>
                Cette écriture sera rattachée à l'exercice {{ $exerciceDestinationLabel }}.
            @endif
        </div>
    @endif
@endif
