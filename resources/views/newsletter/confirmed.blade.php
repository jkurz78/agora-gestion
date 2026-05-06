@extends('layouts.public-minimal')

@section('title', 'Inscription confirmée')

@section('content')
    <h1>Inscription confirmée</h1>

    <p>Merci ! Votre inscription à la newsletter de
       <strong>{{ $association?->nom ?? 'AgoraGestion' }}</strong> est confirmée.</p>

    <p>Vous recevrez nos prochaines actualités à l'adresse que vous nous avez fournie.</p>

    <div class="footer">
        Vous pouvez vous désinscrire à tout moment via le lien présent en pied de chaque message
        que nous vous enverrons.
    </div>
@endsection
