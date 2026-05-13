<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\EmailOpen;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Tiers\DTO\CommunicationsTimelineDTO;
use App\Services\Tiers\DTO\EmailLogLigneDTO;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersCommunicationsTimelineService::class);
});

it('liste un email lié au tiers via tiers_id direct', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Hello',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result)->toBeInstanceOf(CommunicationsTimelineDTO::class)
        ->and($result->total)->toBe(1)
        ->and($result->emails->total())->toBe(1)
        ->and($result->emails->getCollection()->first())
        ->toBeInstanceOf(EmailLogLigneDTO::class)
        ->and($result->emails->getCollection()->first()->objet)->toBe('Hello');
});

it('liste un email envoyé via un participant du tiers (UNION)', function (): void {
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    EmailLog::factory()->create([
        'tiers_id' => null,
        'participant_id' => $participant->id,
        'objet' => 'Via participant',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1)
        ->and($result->emails->getCollection()->first()->objet)->toBe('Via participant')
        ->and($result->emails->getCollection()->first()->participantId)->toBe($participant->id);
});

it('ne duplique pas un email cumulant tiers_id ET participant_id du tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'objet' => 'Cumul',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1);
});

it('exclut les emails d\'autres tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $autre = Tiers::factory()->create();
    EmailLog::factory()->create(['tiers_id' => $autre->id, 'participant_id' => null]);
    EmailLog::factory()->create(['tiers_id' => $tiers->id, 'participant_id' => null, 'objet' => 'OK']);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1)
        ->and($result->emails->getCollection()->first()->objet)->toBe('OK');
});

it('trie les emails par created_at DESC', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Vieux',
        'created_at' => now()->subDays(10),
    ]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'objet' => 'Recent',
        'created_at' => now(),
    ]);

    $result = $this->service->forTiers($tiers);
    $objets = $result->emails->getCollection()->pluck('objet')->all();

    expect($objets)->toBe(['Recent', 'Vieux']);
});

it('pagine à 50 lignes/page', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->count(51)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);

    $page1 = $this->service->forTiers($tiers, page: 1);
    $page2 = $this->service->forTiers($tiers, page: 2);

    expect($page1->emails->count())->toBe(50)
        ->and($page2->emails->count())->toBe(1)
        ->and($page1->total)->toBe(51);
});

it('filtre par catégorie', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Attestation->value,
    ]);
    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Message->value,
    ]);

    $filtered = $this->service->forTiers($tiers, filtreCategorie: CategorieEmail::Attestation->value);

    expect($filtered->emails->count())->toBe(1)
        ->and($filtered->emails->getCollection()->first()->categorie)
        ->toBe(CategorieEmail::Attestation->value)
        ->and($filtered->total)->toBe(2); // total non filtré conservé
});

it('expose les compteurs par catégorie', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->count(3)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Attestation->value,
    ]);
    EmailLog::factory()->count(2)->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
        'categorie' => CategorieEmail::Message->value,
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->compteursParCategorie)
        ->toHaveKey(CategorieEmail::Attestation->value, 3)
        ->toHaveKey(CategorieEmail::Message->value, 2);
});

it('expose nbOuvertures et premiereOuvertureAt', function (): void {
    $tiers = Tiers::factory()->create();
    $log = EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => null,
    ]);
    $expected = now()->subHour();
    EmailOpen::factory()->create([
        'email_log_id' => $log->id,
        'opened_at' => $expected,
    ]);
    EmailOpen::factory()->create([
        'email_log_id' => $log->id,
        'opened_at' => now(),
    ]);

    $result = $this->service->forTiers($tiers);
    $dto = $result->emails->getCollection()->first();

    expect($dto->nbOuvertures)->toBe(2)
        ->and($dto->premiereOuvertureAt)->not->toBeNull()
        ->and($dto->premiereOuvertureAt->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'));
});

it('détecte une pièce jointe et expose le nom de fichier', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()
        ->avecPieceJointe('emails/12/attestation-2025.pdf')
        ->create([
            'tiers_id' => $tiers->id,
            'participant_id' => null,
        ]);

    $result = $this->service->forTiers($tiers);
    $dto = $result->emails->getCollection()->first();

    expect($dto->aPieceJointe)->toBeTrue()
        ->and($dto->attachmentNom)->toBe('attestation-2025.pdf');
});

it('retourne un DTO vide si le tiers n\'a aucun email', function (): void {
    $tiers = Tiers::factory()->create();

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(0)
        ->and($result->emails->total())->toBe(0)
        ->and($result->emails->count())->toBe(0)
        ->and($result->compteursParCategorie)->toBe([]);
});

it('isole les emails par tenant (asso B ne voit pas les emails de asso A)', function (): void {
    // Création d'un email pour le tiers de asso A (TenantContext déjà booté sur $this->association)
    $tiersA = Tiers::factory()->create();
    EmailLog::factory()->create([
        'tiers_id' => $tiersA->id,
        'participant_id' => null,
    ]);

    // Création d'un participant de asso A lié au tiers + email via participant_id
    $participantA = Participant::factory()->create(['tiers_id' => $tiersA->id]);
    EmailLog::factory()->create([
        'tiers_id' => null,
        'participant_id' => $participantA->id,
    ]);

    // Bascule sur asso B
    $assoB = Association::factory()->create();
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create();

    // forTiers sur tiersB ne doit rien retourner (pas d'emails créés pour B)
    $resultB = $this->service->forTiers($tiersB);
    expect($resultB->total)->toBe(0);

    // forTiers sur tiersA depuis tenant B doit aussi retourner 0 (Tiers::find filtré par scope global)
    // Note : on passe l'instance déjà chargée — la requête EmailLog elle-même reste filtrée via participant_id sub-query
    // qui inclut le scope global TenantModel sur Participant
    $resultCross = $this->service->forTiers($tiersA);
    expect($resultCross->total)->toBe(0);
});

it('charge envoyePar, campagne et participant sans planter sur des colonnes inexistantes', function (): void {
    $tiers = Tiers::factory()->create(['nom' => 'Kurz', 'prenom' => 'Anne']);
    $user = User::factory()->create(['nom' => 'Admin']);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    $campagne = CampagneEmail::create([
        'operation_id' => $participant->operation_id,
        'objet' => 'Newsletter mai',
        'corps' => '<p>hello</p>',
        'nb_destinataires' => 1,
        'nb_erreurs' => 0,
        'envoye_par' => $user->id,
    ]);

    EmailLog::factory()->create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'campagne_id' => $campagne->id,
        'envoye_par' => $user->id,
        'objet' => 'Email test',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->total)->toBe(1);

    /** @var EmailLogLigneDTO $ligne */
    $ligne = $result->emails->getCollection()->first();
    expect($ligne)->toBeInstanceOf(EmailLogLigneDTO::class)
        ->and($ligne->envoyeParNom)->toBe('Admin')
        ->and($ligne->campagneNom)->toBe('Newsletter mai')
        ->and($ligne->participantNom)->toBe('Anne KURZ');
});
