<x-mail::message>
# Code de vérification

Votre code de connexion est :

<x-mail::panel>
<strong style="font-size: 24px; letter-spacing: 4px;">{{ $code }}</strong>
</x-mail::panel>

Ce code expire dans **10 minutes**.

Si vous n'avez pas demandé ce code, ignorez cet email.

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
