{{--
    Partial : rendu d'une question de type Information (intertitre / texte explicatif).

    Variables attendues :
      $question — QuestionnaireCampaignQuestion (type === TypeQuestion::Information)

    Pas d'input, pas de label avec astérisque. Affiche le libellé comme sous-titre
    et l'aide comme corps de texte si elle est renseignée.
--}}
<div class="mb-4">
    <h2 class="h5 fw-semibold">{{ $question->libelle }}</h2>

    @if ($question->aide)
        <p class="text-muted mb-0">{{ $question->aide }}</p>
    @endif
</div>
