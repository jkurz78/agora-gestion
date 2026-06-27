<div>
    {{-- Actions --}}
    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="{{ route('questionnaires.resultats.consolides', ['campagneIds' => [$campagne->id]]) }}"
           class="btn btn-outline-secondary">
            <i class="bi bi-diagram-3 me-1"></i>Consolider
        </a>
        <a href="{{ route('questionnaires.campagnes.resultats.pdf', $campagne) }}"
           target="_blank"
           class="btn btn-outline-danger">
            <i class="bi bi-file-earmark-pdf me-1"></i>Exporter en PDF
        </a>
        <a href="{{ route('questionnaires.campagnes.export', $campagne) }}" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i>Exporter en Excel
        </a>
    </div>

    {{-- Bandeau d'avertissement anonymat --}}
    @if ($campagne->anonymise)
    <div class="alert alert-info d-flex gap-2 align-items-start mb-4" role="alert">
        <i class="bi bi-shield-check fs-5 flex-shrink-0"></i>
        <div>
            <strong>Questionnaire confidentiel et non nominatif.</strong>
            Sur un petit groupe, certains retours peuvent rester reconnaissables.
            Les identités ne sont visibles que dans la section &laquo; Souhaitent &ecirc;tre recontact&eacute;s &raquo; pour les participants qui l'ont explicitement demand&eacute;.
        </div>
    </div>
    @endif

    @include('questionnaire.resultats._resultats', ['resultats' => $resultats, 'contacts' => $contacts, 'campagne' => $campagne])
</div>
