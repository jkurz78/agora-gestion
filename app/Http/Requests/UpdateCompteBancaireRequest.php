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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:150'],
            'iban' => ['nullable', 'string', 'max:34'],
            'solde_initial' => ['required', 'numeric'],
            'date_solde_initial' => ['required', 'date'],
        ];
    }
}
