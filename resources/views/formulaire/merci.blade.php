@extends('formulaire.layout')

@section('content')
<div class="text-center py-5">
    <div class="mb-4">
        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
    </div>
    <h3>Merci !</h3>
    <p class="lead text-muted">Vos informations ont bien été enregistrées.</p>
    <p>Vous pouvez fermer cette page.</p>

    @if($helloassoUrl)
        <hr class="my-4">
        <div class="card border-info mx-auto" style="max-width: 500px;">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-heart"></i> Adhésion</h5>
                <p class="card-text">Si vous n'êtes pas encore adhérent(e), vous pouvez compléter votre adhésion en ligne :</p>
                <a href="{{ $helloassoUrl }}" target="_blank" class="btn btn-primary">
                    Adhérer via HelloAsso <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>
        </div>
    @endif
</div>
@endsection
