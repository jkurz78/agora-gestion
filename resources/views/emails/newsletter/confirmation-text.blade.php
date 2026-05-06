Bonjour{{ $prenom ? ' '.$prenom : '' }},

Vous avez demandé à recevoir la newsletter de {{ $associationNom }}.

Pour confirmer votre inscription, ouvrez ce lien :
{{ $confirmUrl }}

Ce lien expire dans {{ config('newsletter.confirmation_ttl_days', 7) }} jours.
Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer ce message.

---
Vous pouvez vous désinscrire à tout moment :
{{ $unsubscribeUrl }}
