<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove the original UNIQUE NULLS NOT DISTINCT constraint.
        // That constraint incorrectly treats every NULL kelas_id (piket) as the same class.
        DB::statement('ALTER TABLE jadwal_mengajar DROP CONSTRAINT IF EXISTS unique_kelas_slot');
        DB::statement('DROP INDEX IF EXISTS unique_kelas_slot');

        // Keep anti-double-booking for class-bound tasks only.
        // NULL kelas_id rows (piket) are intentionally excluded and may coexist in one slot.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX unique_kelas_slot
            ON jadwal_mengajar (kelas_id, hari, waktu_pelajaran_id, jenis_tugas_id)
            WHERE kelas_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_kelas_slot');

        // Restores the previous constraint. This rollback can only succeed when
        // no two piket rows share the same day and time slot.
        DB::statement(<<<'SQL'
            ALTER TABLE jadwal_mengajar
            ADD CONSTRAINT unique_kelas_slot
            UNIQUE NULLS NOT DISTINCT (kelas_id, hari, waktu_pelajaran_id, jenis_tugas_id)
        SQL);
    }
};
