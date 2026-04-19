<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Choisissez votre profil</h1>
                <p class="text-muted">Plusieurs profils sont associés à votre adresse email. Sélectionnez celui que vous souhaitez utiliser.</p>
                <div class="list-group">
                    @foreach ($tiers as $t)
                        <button type="button" wire:click="choose({{ $t->id }})" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span>{{ trim(($t->prenom ?? '').' '.($t->nom ?? '')) }}</span>
                            <span aria-hidden="true">→</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
