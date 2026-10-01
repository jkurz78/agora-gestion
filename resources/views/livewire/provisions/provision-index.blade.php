<div>
    <h4 class="fw-bold mb-3">Écritures de rattachement — {{ $exerciceLabel }}</h4>
    <div class="alert alert-info">Cet écran est en cours de refonte.</div>
    <ul class="list-group">
        @forelse ($provisions as $p)
            <li class="list-group-item">{{ $p->libelle ?? $p->ligne?->libelle }} — {{ number_format((float) $p->montant, 2, ',', ' ') }} €</li>
        @empty
            <li class="list-group-item text-muted">Aucun rattachement sur cet exercice.</li>
        @endforelse
    </ul>
</div>
