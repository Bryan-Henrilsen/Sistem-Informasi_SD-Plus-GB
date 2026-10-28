<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['kepala_sekolah', 'tu', 'bendahara', 'waka_kurikulum', 'guru', 'orang_tua'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}
