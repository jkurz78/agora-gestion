@props(['tiersId'])

<a href="{{ route('tiers.show', $tiersId) }}"
   class="btn btn-sm btn-outline-info"
   title="Voir la fiche complète">
    <i class="bi bi-eye"></i>
</a>
