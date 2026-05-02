<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class SubscribeNewsletterRequest extends FormRequest
{
    public bool $isHoneypotTriggered = false;

    public function authorize(): bool
    {
        // L'autorisation (origine) est portée par le middleware BootTenantFromNewsletterOrigin
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (filled($this->input('bot_trap'))) {
            $this->isHoneypotTriggered = true;
            // On neutralise les inputs : les règles ne peuvent plus échouer,
            // le controller détecte le flag et court-circuite avec un 200 silencieux.
            $this->replace([]);
        }
    }

    public function rules(): array
    {
        if ($this->isHoneypotTriggered) {
            return [];
        }

        return [
            'email' => ['required', 'email', 'max:255'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'consent' => ['required', 'accepted'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => 'validation_failed',
            'fields' => $validator->errors()->toArray(),
        ], 422));
    }
}
