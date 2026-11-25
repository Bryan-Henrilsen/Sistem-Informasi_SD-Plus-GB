<?php

use App\Domains\JurnalGuru\Models\ForumPesan;
use App\Models\Guru;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Forum privat guru ↔ Kepala Sekolah (PRD B.2 FR-2–FR-4, Ringkasan v1.11 #51)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-11-25 10:00');

    $this->kepsek = User::factory()->create(['name' => 'Kepala Uji'])->assignRole('kepala_sekolah');
    $this->ani = forumGuru('Ani Guru');
    $this->budi = forumGuru('Budi Guru');
});

function forumGuru(string $nama): Guru
{
    $user = User::factory()->create(['name' => $nama])->assignRole('guru');

    return Guru::create(['user_id' => $user->id, 'kode_guru' => strtoupper(substr($nama, 0, 3)).$user->id]);
}

test('guru menulis ke forumnya, Kepala Sekolah membaca lalu membalas; tanda dibaca dan badge berubah', function () {
    $ani = $this->ani->user;

    // Guru dibawa langsung ke percakapannya sendiri
    $this->actingAs($ani)->get('/jurnal-guru/forum')->assertRedirect("/jurnal-guru/forum/{$this->ani->id}");
    $this->actingAs($ani)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => "  Proyektor kelas 3A rusak.\nMohon dibantu.  "])->assertSessionHasNoErrors();

    $pesan = ForumPesan::sole();
    expect($pesan->isi)->toBe("Proyektor kelas 3A rusak.\nMohon dibantu.")->and($pesan->pengirim_id)->toBe($ani->id);

    // Kepala Sekolah melihat 1 pesan belum dibaca di badge dan daftar
    $this->actingAs($this->kepsek)->get('/jurnal-guru/forum')
        ->assertInertia(fn (Assert $page) => $page
            ->component('jurnal-guru/forum/index')
            ->where('badgeForum', 1)
            ->where('thread.0.nama', 'Ani Guru')
            ->where('thread.0.belum_dibaca', 1));

    // Membuka percakapan menandai pesan guru dibaca, lalu membalas
    $this->actingAs($this->kepsek)->get("/jurnal-guru/forum/{$this->ani->id}")
        ->assertInertia(fn (Assert $page) => $page->component('jurnal-guru/forum/thread')->where('sebagaiKepsek', true)->has('pesan', 1));
    expect($pesan->fresh()->dibaca_at)->not->toBeNull();
    $this->actingAs($this->kepsek)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Besok teknisi datang.'])->assertSessionHasNoErrors();

    // Guru melihat balasan baru di badge, lalu membacanya
    $this->actingAs($ani)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('badgeForum', 1)
        ->where('tugas.0.judul', 'Balasan Kepala Sekolah')
        ->where('tugas.0.jumlah', 1));
    $this->actingAs($ani)->get("/jurnal-guru/forum/{$this->ani->id}")
        ->assertInertia(fn (Assert $page) => $page->where('pesan.0.dari_guru', true)->where('pesan.1.dari_guru', false)->where('pesan.0.dibaca_at', fn ($v) => $v !== null));
    expect(ForumPesan::whereNull('dibaca_at')->count())->toBe(0);
});

test('guru hanya bisa membuka percakapannya sendiri; role lain tidak punya akses', function () {
    $this->actingAs($this->budi->user)->get("/jurnal-guru/forum/{$this->ani->id}")->assertForbidden();
    $this->actingAs($this->budi->user)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Halo'])->assertForbidden();

    $waka = User::factory()->create()->assignRole('waka_kurikulum');
    $this->actingAs($waka)->get('/jurnal-guru/forum')->assertForbidden();
    $this->actingAs($waka)->get("/jurnal-guru/forum/{$this->ani->id}")->assertForbidden();

    // Akun ber-role guru tanpa data guru tidak punya forum
    $tanpaData = User::factory()->create()->assignRole('guru');
    $this->actingAs($tanpaData)->get('/jurnal-guru/forum')->assertForbidden();

    expect(ForumPesan::count())->toBe(0);
});

test('Kepala Sekolah boleh memulai percakapan; pesan kosong atau terlalu panjang ditolak', function () {
    $this->actingAs($this->kepsek)->post("/jurnal-guru/forum/{$this->budi->id}", ['isi' => 'Mohon kumpulkan nilai rapor Jumat.'])->assertSessionHasNoErrors();
    $this->actingAs($this->kepsek)->post("/jurnal-guru/forum/{$this->budi->id}", ['isi' => '   '])->assertSessionHasErrors('isi');
    $this->actingAs($this->kepsek)->post("/jurnal-guru/forum/{$this->budi->id}", ['isi' => str_repeat('a', 2001)])->assertSessionHasErrors('isi');

    expect(ForumPesan::where('guru_id', $this->budi->id)->count())->toBe(1);
});

test('pengirim boleh menghapus pesannya dalam 15 menit; sesudahnya atau oleh orang lain ditolak', function () {
    $ani = $this->ani->user;
    $this->actingAs($ani)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Salah ketik']);
    $this->actingAs($ani)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Pesan penting']);
    [$salah, $penting] = ForumPesan::orderBy('id')->get()->all();

    $this->actingAs($this->kepsek)->delete("/jurnal-guru/forum/pesan/{$salah->id}")->assertSessionHasErrors('pesan');
    $this->actingAs($ani)->delete("/jurnal-guru/forum/pesan/{$salah->id}")->assertSessionHasNoErrors();
    expect(ForumPesan::find($salah->id))->toBeNull();

    $this->travel(16)->minutes();
    $this->actingAs($ani)->delete("/jurnal-guru/forum/pesan/{$penting->id}")->assertSessionHasErrors('pesan');
    expect(ForumPesan::find($penting->id))->not->toBeNull();
});

test('percakapan guru nonaktif hanya bisa dibaca', function () {
    $this->actingAs($this->ani->user)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Pesan lama']);
    $this->ani->update(['status_aktif' => false]);

    $this->actingAs($this->kepsek)->get("/jurnal-guru/forum/{$this->ani->id}")->assertOk();
    $this->actingAs($this->kepsek)->post("/jurnal-guru/forum/{$this->ani->id}", ['isi' => 'Balasan'])->assertSessionHasErrors('isi');

    $this->actingAs($this->kepsek)->get('/jurnal-guru/forum')
        ->assertInertia(fn (Assert $page) => $page->where('thread', fn ($thread) => collect($thread)->firstWhere('nama', 'Ani Guru')['aktif'] === false));
});
