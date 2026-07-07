<div class="d-flex justify-content-between align-items-center mb-2">
    <a href="{{ route('questionnaires.modeles.index') }}" class="btn btn-sm btn-link px-0">&larr; Retour aux modèles</a>
    <h1 class="h4 mb-0">{{ $template->titre_interne }}</h1>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $active === 'infos' ? 'active' : '' }}"
           href="{{ route('questionnaires.modeles.infos', $template) }}"
           @if($active === 'infos') aria-current="page" @endif>Informations</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'textes' ? 'active' : '' }}"
           href="{{ route('questionnaires.modeles.textes', $template) }}"
           @if($active === 'textes') aria-current="page" @endif>Textes</a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $active === 'questions' ? 'active' : '' }}"
           href="{{ route('questionnaires.modeles.editor', $template) }}"
           @if($active === 'questions') aria-current="page" @endif>Questions</a>
    </li>
    <li class="nav-item ms-auto">
        <a class="nav-link btn-outline-secondary border"
           href="{{ route('questionnaires.modeles.apercu', $template) }}"
           target="_blank">Prévisualiser</a>
    </li>
</ul>
