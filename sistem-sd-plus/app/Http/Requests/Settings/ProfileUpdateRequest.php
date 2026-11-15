<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Support\NomorHp;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    // Orang tua: no HP dinormalkan dulu dan email boleh dikosongkan (#36)
    protected function prepareForValidation(): void
    {
        if ($this->untukOrangTua()) {
            $this->merge([
                'no_hp' => NomorHp::normal($this->input('no_hp')) ?? $this->input('no_hp'),
                'email' => trim((string) $this->input('email')) ?: null,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->untukOrangTua() ? $this->orangTuaRules($this->user()->id) : $this->profileRules($this->user()->id);
    }

    public function messages(): array
    {
        return $this->untukOrangTua() ? $this->orangTuaMessages() : [];
    }

    private function untukOrangTua(): bool
    {
        return $this->user()->hasRole('orang_tua');
    }
}
