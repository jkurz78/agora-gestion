@extends('layouts.public-minimal')

@section('title', 'Désinscription effectuée')

@section('content')
    <h1>Vous êtes désinscrit·e</h1>

    <p>Votre adresse n'est plus inscrite à la newsletter de
       <strong>{{ $association?->nom ?? 'AgoraGestion' }}</strong>.</p>

    <p>Vous ne recevrez plus de communication de notre part.</p>

    <div class="footer">
        Si vous avez changé d'avis, vous pouvez vous réinscrire depuis notre site web.
    </div>
@endsection
