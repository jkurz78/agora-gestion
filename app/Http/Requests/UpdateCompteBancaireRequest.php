<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompteBancaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'actif_recettes_depenses' => $this->boolean('actif_recettes_depenses'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:150'],
            'iban' => ['nullable', 'string', 'max:34'],
            'bic' => ['nullable', 'string', 'max:11'],
            'domiciliation' => ['nullable', 'string', 'max:255'],
            'solde_initial' => ['required', 'numeric'],
            'date_solde_initial' => ['required', 'date'],
            'actif_recettes_depenses' => ['boolean'],
        ];
    }
}
