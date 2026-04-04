<x-mail::message>
# Mot de passe modifié

Bonjour {{ $user->nom }},

Votre mot de passe sur **{{ config('app.name') }}** a été modifié par {{ $changedByName }}.

Si vous n'êtes pas à l'origine de cette demande, contactez immédiatement votre administrateur.

Cordialement,<br>
{{ config('app.name') }}
</x-mail::message>
