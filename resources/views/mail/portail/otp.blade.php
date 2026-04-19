@component('mail::message')
# Votre code de connexion

Bonjour,

Vous avez demandé à vous connecter au portail de **{{ $association->nom }}**.

Voici votre code à usage unique :

@component('mail::panel')
<div style="text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; font-family: monospace;">
{{ $code }}
</div>
@endcomponent

Ce code est valable **10 minutes**. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.

Pour votre sécurité, ne partagez pas ce code.

Cordialement,
{{ config('app.name') }}
@endcomponent
