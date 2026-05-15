<div class="mb-3">
    @foreach($alertes as $token)
        @php
            $participation = $token->participant;
            $op            = $participation->operation;
            $typeNom       = $op->typeOperation->nom;
        @endphp
        <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3">
            <div class="flex-grow-1">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Action requise</strong> — Vous avez été invité à répondre au questionnaire pour
                <em>{{ $typeNom }}</em> · <em>{{ $op->nom }}</em>.
            </div>
            <a href="{{ route('formulaire.index', ['token' => $token->token]) }}"
               target="_blank" rel="noopener"
               class="btn btn-sm btn-warning text-nowrap">
                Ouvrir le questionnaire
            </a>
        </div>
    @endforeach

    @if($alertesAutres > 0)
        <div class="text-muted small">
            + {{ $alertesAutres }} autre(s) action(s) en attente, voir Mes activités.
        </div>
    @endif
</div>
