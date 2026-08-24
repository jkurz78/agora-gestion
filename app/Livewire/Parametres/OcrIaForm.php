<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Exceptions\OcrAnalysisException;
use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Services\InvoiceOcrService;
use App\Support\CurrentAssociation;
use Illuminate\View\View;
use Livewire\Component;

final class OcrIaForm extends Component
{
    use AutoriseEcranParametre;

    public string $anthropic_api_key = '';

    public bool $cleDejaEnregistree = false;

    public ?string $invoice_ocr_model = null;

    /** @var array<string, string> [id => libellé] des modèles disponibles pour la clé */
    public array $availableOcrModels = [];

    public string $ocrModelsFlash = '';

    public string $ocrModelsFlashType = '';

    protected function cleEcranParametre(): string
    {
        return 'ocr-ia';
    }

    public function mount(): void
    {
        $association = CurrentAssociation::tryGet();
        if ($association) {
            $this->cleDejaEnregistree = $association->anthropic_api_key !== null;
            $this->invoice_ocr_model = $association->invoice_ocr_model;
        }

        // Le sélecteur contient au moins le modèle déjà choisi, pour ne jamais
        // être vide avant un chargement de la liste vivante.
        if ($this->invoice_ocr_model !== null && $this->invoice_ocr_model !== '') {
            $this->availableOcrModels = [$this->invoice_ocr_model => $this->invoice_ocr_model];
        }
    }

    /**
     * Charge la liste des modèles réellement disponibles pour la clé API saisie,
     * via GET /v1/models. L'utilisateur sélectionne ensuite dans le combo —
     * aucun ID à deviner ni à saisir à la main.
     */
    public function chargerModelesOcr(): void
    {
        $this->ocrModelsFlash = '';
        $this->ocrModelsFlashType = '';

        $cle = $this->anthropic_api_key !== ''
            ? $this->anthropic_api_key
            : CurrentAssociation::tryGet()?->anthropic_api_key;

        if ($cle === null || $cle === '') {
            $this->ocrModelsFlash = 'Renseignez d\'abord la clé API Anthropic, puis enregistrez ou rechargez la liste.';
            $this->ocrModelsFlashType = 'warning';

            return;
        }

        try {
            $modeles = InvoiceOcrService::fetchAvailableModels($cle);
        } catch (OcrAnalysisException $e) {
            $this->ocrModelsFlash = 'Impossible de récupérer la liste des modèles (clé invalide ou API injoignable).';
            $this->ocrModelsFlashType = 'danger';

            return;
        }

        // On conserve le modèle déjà choisi même s'il n'est plus listé (retiré),
        // pour ne pas effacer silencieusement la configuration.
        if ($this->invoice_ocr_model !== null && $this->invoice_ocr_model !== '' && ! isset($modeles[$this->invoice_ocr_model])) {
            $modeles[$this->invoice_ocr_model] = $this->invoice_ocr_model.' (retiré ?)';
        }

        $this->availableOcrModels = $modeles;
        $this->ocrModelsFlash = count($modeles).' modèle(s) disponible(s) chargé(s).';
        $this->ocrModelsFlashType = 'success';
    }

    public function save(): void
    {
        $this->validate([
            'anthropic_api_key' => ['nullable', 'string', 'max:255'],
            'invoice_ocr_model' => ['nullable', 'string', 'max:255'],
        ]);

        $association = CurrentAssociation::get();

        if ($this->anthropic_api_key !== '') {
            $cle = $this->anthropic_api_key;
        } elseif ($this->cleDejaEnregistree) {
            $cle = $association->anthropic_api_key;
        } else {
            $cle = null;
        }

        $association->fill([
            'anthropic_api_key' => $cle,
            'invoice_ocr_model' => $this->invoice_ocr_model ?: null,
        ])->save();

        $this->anthropic_api_key = '';
        $this->cleDejaEnregistree = $association->anthropic_api_key !== null;

        $this->dispatch('form-saved');
        session()->flash('success', 'Réglages OCR / IA mis à jour.');
    }

    public function render(): View
    {
        return view('livewire.parametres.ocr-ia-form');
    }
}
