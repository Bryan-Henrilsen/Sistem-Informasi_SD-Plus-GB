<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\NomorHp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Halaman daftar hanya untuk orang tua (FR-1, Ringkasan v1.11 #36): nama, no HP, email opsional, password.
     * Akun staf dibuat lewat seeder/admin, bukan lewat halaman ini.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['no_hp'] = NomorHp::normal($input['no_hp'] ?? null) ?? ($input['no_hp'] ?? null);
        $input['email'] = trim((string) ($input['email'] ?? '')) ?: null;

        Validator::make($input, [
            ...$this->orangTuaRules(),
            'password' => $this->passwordRules(),
        ], $this->orangTuaMessages())->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'no_hp' => $input['no_hp'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            return $user->assignRole('orang_tua');
        });
    }
}
