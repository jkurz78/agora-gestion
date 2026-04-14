<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutPresence;
use App\Mail\AttestationPresenceMail;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class AttestationModal extends Component
{
    public Operation $operation;

    public string $mode = ''; // 'seance' or 'recap'

    public bool $showModal = false;

    public ?int $seanceId = null;

    public ?int $participantId = null;

    public string $modalTitle = '';

    /** @var array<int, array{id: int, nom: string, prenom: string, email: ?string, checked: bool}> */
    public array $presentParticipants = [];

    // For recap mode
    /** @var array<int, array{numero: int, date: string, titre: ?string}> */
    public array $seancesPresent = [];

    public int $totalSeances = 0;

    public string $participantNom = '';

    public ?string $participantEmail = null;

    public string $resultMessage = '';

    public string $resultType = '';

    public bool $hasCachet = false;

    public bool $hasEmailFrom = false;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $association = Association::find(1);
        $this->hasEmailFrom = (bool) ($operation->typeOperation?->effectiveEmailFrom() ?: $association?->email_from);
        $this->hasCachet = (bool) $association?->cachet_signature_path;
    }

    #[On('open-seance-modal')]
    public function openSeanceModal(int $seanceId): void
    {
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $this->seanceId = $seanceId;
        $this->mode = 'seance';
        $this->resultMessage = '';
        $this->modalTitle = "Attestation — Séance n°{$seance->numero} du ".$seance->date->format('d/m/Y');

        // Load participants and filter presents (statut is encrypted, must filter in PHP)
        $participants = Participant::with(['tiers', 'presences' => fn ($q) => $q->where('seance_id', $seanceId)])
            ->where('operation_id', $this->operation->id)
            ->get();

        $this->presentParticipants = [];
        foreach ($participants as $p) {
            $presence = $p->presences->first();
            if ($presence && $presence->statut === StatutPresence::Present->value) {
                $this->presentParticipants[] = [
                    'id' => $p->id,
                    'nom' => $p->tiers->nom ?? '',
                    'prenom' => $p->tiers->prenom ?? '',
                    'email' => $p->tiers->email,
                    'checked' => true,
                ];
            }
        }

        $this->showModal = true;
    }

    #[On('open-recap-modal')]
    public function openRecapModal(int $participantId): void
    {
        $participant = Participant::with('tiers')
            ->where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $this->participantId = $participantId;
        $this->mode = 'recap';
        $this->resultMessage = '';
        $this->participantNom = trim(($participant->tiers->prenom ?? '').' '.($participant->tiers->nom ?? ''));
        $this->participantEmail = $participant->tiers->email;
        $this->modalTitle = "Attestation récapitulative — {$this->participantNom}";

        // Load all seances and filter where participant was present (encrypted statut)
        $allSeances = Seance::where('operation_id', $this->operation->id)->orderBy('numero')->get();
        $presences = $participant->presences()->get()->keyBy('seance_id');

        $this->seancesPresent = [];
        foreach ($allSeances as $s) {
            $presence = $presences->get($s->id);
            if ($presence && $presence->statut === StatutPresence::Present->value) {
                $this->seancesPresent[] = [
                    'numero' => $s->numero,
                    'date' => $s->date?->format('d/m/Y') ?? '—',
                    'titre' => $s->titre,
                ];
            }
        }
        $this->totalSeances = $allSeances->count();

        $this->showModal = true;
    }

    public function toggleParticipant(int $id): void
    {
        foreach ($this->presentParticipants as &$p) {
            if ($p['id'] === $id) {
                $p['checked'] = ! $p['checked'];
                break;
            }
        }
    }

    public function envoyerParEmail(): void
    {
        if ($this->mode === 'seance') {
            $this->envoyerSeanceParEmail();
        } else {
            $this->envoyerRecapParEmail();
        }
    }

    public function render(): View
    {
        return view('livewire.attestation-modal');
    }

    private function envoyerSeanceParEmail(): void
    {
        $seance = Seance::findOrFail($this->seanceId);
        $typeOp = $this->operation->typeOperation;
        $template = EmailTemplate::where('categorie', 'attestation')
            ->where('type_operation_id', $typeOp?->id)
            ->first()
            ?? EmailTemplate::where('categorie', 'attestation')
                ->whereNull('type_operation_id')
                ->first();

        $sent = 0;
        $errors = 0;
        $noEmail = 0;

        $checked = collect($this->presentParticipants)->where('checked', true);

        foreach ($checked as $pData) {
            if (! $pData['email']) {
                $noEmail++;

                continue;
            }

            $participant = Participant::with(['tiers', 'donneesMedicales'])->find($pData['id']);
            if (! $participant) {
                continue;
            }

            try {
                // Generate individual PDF in memory
                $pdfData = $this->generateSeancePdfData($participant, $seance);

                $mail = new AttestationPresenceMail(
                    prenomParticipant: $participant->tiers->prenom ?? '',
                    nomParticipant: $participant->tiers->nom ?? '',
                    nomOperation: $this->operation->nom,
                    nomTypeOperation: $typeOp->nom ?? '',
                    dateDebut: $this->operation->date_debut?->format('d/m/Y') ?? '',
                    dateFin: $this->operation->date_fin?->format('d/m/Y') ?? '',
                    nombreSeances: (string) ($this->operation->nombre_seances ?? ''),
                    numeroSeance: (string) $seance->numero,
                    dateSeance: $seance->date->format('d/m/Y'),
                    customObjet: $template?->objet,
                    customCorps: $template?->corps,
                    pdfContent: $pdfData,
                    pdfFilename: "Attestation présence - S{$seance->numero}.pdf",
                    libelleArticle: $typeOp->libelle_article,
                    blocSeances: $this->buildBlocSeances('seance', $seance),
                    typeOperationId: $typeOp?->id,
                );

                Mail::mailer()
                    ->to($pData['email'])
                    ->send($mail->from($typeOp->effectiveEmailFrom(), $typeOp->effectiveEmailFromName()));

                EmailLog::create([
                    'tiers_id' => $participant->tiers_id,
                    'participant_id' => $participant->id,
                    'operation_id' => $this->operation->id,
                    'categorie' => 'attestation',
                    'email_template_id' => $template?->id,
                    'destinataire_email' => $pData['email'],
                    'destinataire_nom' => trim($pData['nom'].' '.$pData['prenom']),
                    'objet' => $mail->envelope()->subject,
                    'statut' => 'envoye',
                    'envoye_par' => Auth::id(),
                ]);

                $sent++;
            } catch (\Throwable $e) {
                EmailLog::create([
                    'tiers_id' => $participant->tiers_id,
                    'participant_id' => $participant->id,
                    'operation_id' => $this->operation->id,
                    'categorie' => 'attestation',
                    'email_template_id' => $template?->id,
                    'destinataire_email' => $pData['email'],
                    'destinataire_nom' => trim($pData['nom'].' '.$pData['prenom']),
                    'objet' => $template?->objet ?? 'Attestation',
                    'statut' => 'erreur',
                    'erreur_message' => $e->getMessage(),
                    'envoye_par' => Auth::id(),
                ]);
                $errors++;
            }
        }

        $parts = [];
        if ($sent > 0) {
            $parts[] = "{$sent} envoyé(s)";
        }
        if ($errors > 0) {
            $parts[] = "{$errors} erreur(s)";
        }
        if ($noEmail > 0) {
            $parts[] = "{$noEmail} sans email";
        }
        $this->resultMessage = implode(', ', $parts).'.';
        $this->resultType = $errors > 0 ? 'warning' : 'success';
    }

    private function envoyerRecapParEmail(): void
    {
        $participant = Participant::with(['tiers', 'donneesMedicales'])->find($this->participantId);
        if (! $participant || ! $this->participantEmail) {
            $this->resultMessage = 'Impossible d\'envoyer (pas d\'email).';
            $this->resultType = 'danger';

            return;
        }

        $typeOp = $this->operation->typeOperation;
        $template = EmailTemplate::where('categorie', 'attestation')
            ->where('type_operation_id', $typeOp?->id)
            ->first()
            ?? EmailTemplate::where('categorie', 'attestation')
                ->whereNull('type_operation_id')
                ->first();

        try {
            $pdfData = $this->generateRecapPdfData($participant);
            $prenom = $participant->tiers->prenom ?? '';
            $nom = $participant->tiers->nom ?? '';

            $mail = new AttestationPresenceMail(
                prenomParticipant: $prenom,
                nomParticipant: $nom,
                nomOperation: $this->operation->nom,
                nomTypeOperation: $typeOp->nom ?? '',
                dateDebut: $this->operation->date_debut?->format('d/m/Y') ?? '',
                dateFin: $this->operation->date_fin?->format('d/m/Y') ?? '',
                nombreSeances: (string) ($this->operation->nombre_seances ?? ''),
                numeroSeance: null,
                dateSeance: null,
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                pdfContent: $pdfData,
                pdfFilename: "Attestation présence - {$prenom} {$nom}.pdf",
                libelleArticle: $typeOp->libelle_article ?? null,
                blocSeances: $this->buildBlocSeances('recap', null, $this->seancesPresent, $this->totalSeances),
                typeOperationId: $typeOp?->id,
            );

            Mail::mailer()
                ->to($this->participantEmail)
                ->send($mail->from($typeOp->effectiveEmailFrom(), $typeOp->effectiveEmailFromName()));

            EmailLog::create([
                'tiers_id' => $participant->tiers_id,
                'participant_id' => $participant->id,
                'operation_id' => $this->operation->id,
                'categorie' => 'attestation',
                'email_template_id' => $template?->id,
                'destinataire_email' => $this->participantEmail,
                'destinataire_nom' => trim("{$nom} {$prenom}"),
                'objet' => $mail->envelope()->subject,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
            ]);

            $this->resultMessage = "Email envoyé à {$this->participantEmail}.";
            $this->resultType = 'success';
        } catch (\Throwable $e) {
            EmailLog::create([
                'tiers_id' => $participant->tiers_id,
                'participant_id' => $participant->id,
                'operation_id' => $this->operation->id,
                'categorie' => 'attestation',
                'email_template_id' => $template?->id,
                'destinataire_email' => $this->participantEmail,
                'destinataire_nom' => trim(($participant->tiers->nom ?? '').' '.($participant->tiers->prenom ?? '')),
                'objet' => $template?->objet ?? 'Attestation',
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            $this->resultMessage = "Erreur : {$e->getMessage()}";
            $this->resultType = 'danger';
        }
    }

    /** Generate PDF content in memory for a single participant + seance */
    private function generateSeancePdfData(Participant $participant, Seance $seance): string
    {
        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime, $cachetBase64, $cachetMime] = $this->resolveAssociationData();

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.attestation-presence', [
            'mode' => 'seance',
            'operation' => $this->operation,
            'seance' => $seance,
            'participants' => collect([$participant]),
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return $pdf->output();
    }

    /** Generate recap PDF content in memory for a participant */
    private function generateRecapPdfData(Participant $participant): string
    {
        // Reload seances present (encrypted statut requires PHP filter)
        $allSeances = Seance::where('operation_id', $this->operation->id)->orderBy('numero')->get();
        $presences = $participant->presences()->get()->keyBy('seance_id');
        $seancesPresent = $allSeances->filter(fn ($s) => ($presences->get($s->id)?->statut ?? '') === StatutPresence::Present->value);

        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime, $cachetBase64, $cachetMime] = $this->resolveAssociationData();

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.attestation-presence', [
            'mode' => 'recap',
            'operation' => $this->operation,
            'participant' => $participant,
            'seancesPresent' => $seancesPresent,
            'totalSeances' => $allSeances->count(),
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return $pdf->output();
    }

    /**
     * Build HTML bloc for {bloc_seances} variable.
     * Mode seance: single line. Mode recap: table of séances.
     */
    private function buildBlocSeances(string $mode, ?Seance $seance = null, array $seancesPresent = [], int $totalSeances = 0): string
    {
        if ($mode === 'seance' && $seance) {
            $titre = $seance->titre ? ', « '.$seance->titre.' »' : '';
            $date = $seance->date?->translatedFormat('j F Y') ?? '';

            return '<p>Séance n°'.$seance->numero.$titre.' du '.$date.'.</p>';
        }

        if (empty($seancesPresent)) {
            return '<p>Aucune séance.</p>';
        }

        $html = '<table style="border-collapse:collapse;width:100%;margin:10px 0;font-size:13px">'
            .'<tr style="background:#f0f0f0"><th style="padding:6px 10px;text-align:left;border:1px solid #ddd">Séance</th>'
            .'<th style="padding:6px 10px;text-align:left;border:1px solid #ddd">Date</th>'
            .'<th style="padding:6px 10px;text-align:left;border:1px solid #ddd">Titre</th></tr>';

        foreach ($seancesPresent as $s) {
            $html .= '<tr>'
                .'<td style="padding:4px 10px;border:1px solid #ddd">'.$s['numero'].'</td>'
                .'<td style="padding:4px 10px;border:1px solid #ddd">'.$s['date'].'</td>'
                .'<td style="padding:4px 10px;border:1px solid #ddd">'.($s['titre'] ?? '—').'</td>'
                .'</tr>';
        }

        $html .= '</table>';
        $html .= '<p><strong>'.count($seancesPresent).' séance(s) sur '.$totalSeances.'</strong></p>';

        return $html;
    }

    /** Same logo resolution as AttestationPresencePdfController::getAssociationData() */
    private function resolveAssociationData(): array
    {
        $association = Association::find(1);
        $assoBase64 = null;
        $assoMime = 'image/png';

        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $assoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $headerBase64 = $assoBase64;
        $headerMime = $assoMime;
        $footerBase64 = null;
        $footerMime = 'image/png';

        $typeLogo = $this->operation->typeOperation?->logo_path;
        if ($typeLogo && Storage::disk('public')->exists($typeLogo)) {
            $headerBase64 = base64_encode(Storage::disk('public')->get($typeLogo));
            $ext = strtolower(pathinfo($typeLogo, PATHINFO_EXTENSION));
            $headerMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            $footerBase64 = $assoBase64;
            $footerMime = $assoMime;
        }

        $cachetBase64 = null;
        $cachetMime = 'image/png';
        if ($association?->cachet_signature_path && Storage::disk('public')->exists($association->cachet_signature_path)) {
            $cachetBase64 = base64_encode(Storage::disk('public')->get($association->cachet_signature_path));
            $ext = strtolower(pathinfo($association->cachet_signature_path, PATHINFO_EXTENSION));
            $cachetMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        return [$association, $headerBase64, $headerMime, $footerBase64, $footerMime, $cachetBase64, $cachetMime];
    }
}
