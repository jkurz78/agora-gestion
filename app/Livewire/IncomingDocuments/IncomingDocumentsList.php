<?php

declare(strict_types=1);

namespace App\Livewire\IncomingDocuments;

use App\Models\IncomingDocument;
use App\Models\Operation;
use App\Models\Seance;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\IncomingDocuments\IncomingDocumentIngester;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class IncomingDocumentsList extends Component
{
    use WithFileUploads;
    use WithPagination;

    public $fichierAjoute = null;

    public bool $showAssignModal = false;

    public ?int $docIdToAssign = null;

    public ?int $selectedOperationId = null;

    public ?int $selectedSeanceId = null;

    public string $pageUrl = '';

    public function mount(): void
    {
        $this->pageUrl = url()->current();
    }

    public function ajouter(IncomingDocumentIngester $ingester): void
    {
        $this->validate([
            'fichierAjoute' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $tempPath = $this->fichierAjoute->getRealPath();

        $file = new IncomingDocumentFile(
            tempPath: $tempPath,
            originalFilename: $this->fichierAjoute->getClientOriginalName(),
            source: 'manual-inbox',
            senderEmail: null,
            recipientEmail: null,
            subject: null,
            receivedAt: new DateTimeImmutable,
            sourceMessageId: null,
        );

        $result = $ingester->ingest($file);

        if ($result->outcome === 'handled') {
            session()->flash('success', 'Document traité automatiquement par le handler « '.$result->handlerName.' ».');
        } else {
            session()->flash('info', 'Document ajouté à la liste en attente.');
        }

        $this->reset('fichierAjoute');
        $this->redirect($this->pageUrl, navigate: false);
    }

    public function ouvrirAssignation(int $docId): void
    {
        $this->docIdToAssign = $docId;
        $this->selectedOperationId = null;
        $this->selectedSeanceId = null;
        $this->showAssignModal = true;
    }

    public function fermerAssignation(): void
    {
        $this->showAssignModal = false;
        $this->docIdToAssign = null;
    }

    public function assignerASeance(): void
    {
        $this->validate([
            'selectedSeanceId' => ['required', 'integer', 'exists:seances,id'],
        ]);

        $doc = IncomingDocument::findOrFail($this->docIdToAssign);
        $seance = Seance::findOrFail($this->selectedSeanceId);

        DB::transaction(function () use ($doc, $seance): void {
            $finalPath = "emargement/seance-{$seance->id}.pdf";

            if ($seance->feuille_signee_path !== null) {
                Log::info('Feuille signée écrasée via assignation depuis inbox', [
                    'seance_id' => $seance->id,
                    'doc_id' => $doc->id,
                ]);
                Storage::disk('local')->delete($seance->feuille_signee_path);
            }

            Storage::disk('local')->move($doc->storage_path, $finalPath);

            $seance->update([
                'feuille_signee_path' => $finalPath,
                'feuille_signee_at' => now(),
                'feuille_signee_source' => $doc->sender_email === 'upload-manuel' ? 'manual' : 'email',
                'feuille_signee_sender_email' => $doc->sender_email === 'upload-manuel' ? null : $doc->sender_email,
            ]);

            $doc->delete();
        });

        session()->flash('success', 'Document attaché à la séance '.$seance->numero.'.');
        $this->fermerAssignation();
        $this->redirect($this->pageUrl, navigate: false);
    }

    public function supprimer(int $docId): void
    {
        $doc = IncomingDocument::findOrFail($docId);
        Storage::disk('local')->delete($doc->storage_path);
        $doc->delete();
        session()->flash('success', 'Document supprimé.');
        $this->redirect($this->pageUrl, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.incoming-documents.list', [
            'documents' => IncomingDocument::where('association_id', 1)
                ->orderBy('received_at', 'desc')
                ->paginate(20),
            'operations' => $this->showAssignModal
                ? Operation::orderBy('nom')->get()
                : collect(),
            'seances' => $this->selectedOperationId !== null
                ? Seance::where('operation_id', $this->selectedOperationId)
                    ->orderBy('numero', 'desc')
                    ->get()
                : collect(),
        ]);
    }
}
