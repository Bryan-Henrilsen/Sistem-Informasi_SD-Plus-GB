<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    // Akun orang tua: no HP wajib (bentuk baku 08…, sudah dinormalkan NomorHp), email opsional untuk lupa password (#36)
    protected function orangTuaRules(?int $userId = null): array
    {
        $unik = fn (string $kolom) => $userId === null ? Rule::unique(User::class, $kolom) : Rule::unique(User::class, $kolom)->ignore($userId);

        return [
            'name' => $this->nameRules(),
            'no_hp' => ['required', 'string', 'regex:/^08\d{8,12}$/', $unik('no_hp')],
            'email' => ['nullable', 'string', 'email', 'max:255', $unik('email')],
        ];
    }

    protected function orangTuaMessages(): array
    {
        return [
            'no_hp.required' => 'Nomor HP wajib diisi.',
            'no_hp.regex' => 'Nomor HP tidak valid. Contoh: 0812 3456 7890.',
            'no_hp.unique' => 'Nomor HP ini sudah terdaftar. Silakan login, atau hubungi TU bila lupa password.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
