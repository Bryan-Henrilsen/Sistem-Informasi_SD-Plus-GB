<?php

use App\Domains\LesEkskul\Services\AkunStafService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// Akun Guru & Staf oleh Kepala Sekolah (Ringkasan v1.11 #46)
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->kepsek = User::factory()->create(['name' => 'Kepsek Fiktif'])->assignRole('kepala_sekolah');
    $this->tu = User::factory()->create()->assignRole('tu');
    $this->data = ['nama' => 'Guru Baru, S.Pd.', 'email' => 'guru.baru@contoh.test', 'peran' => ['guru', 'bendahara'], 'kode_guru' => 'gb', 'nuptk' => '123'];
    $this->login = fn (string $email, string $password) => $this->post(route('login.store'), ['login' => $email, 'password' => $password]);
});

test('Kepala Sekolah menambah akun guru; password awal tampil sekali dan bisa dipakai login; TU tidak boleh membuka', function () {
    $this->actingAs($this->tu)->get('/les-ekskul/akun-staf')->assertForbidden();
    $this->actingAs($this->tu)->post('/les-ekskul/akun-staf', $this->data)->assertForbidden();

    $respon = $this->actingAs($this->kepsek)->post('/les-ekskul/akun-staf', $this->data)->assertSessionHasNoErrors();
    $respon->assertInertiaFlash('password.email', 'guru.baru@contoh.test');

    $guru = User::where('email', 'guru.baru@contoh.test')->sole();
    expect($guru->getRoleNames()->sort()->values()->all())->toBe(['bendahara', 'guru'])
        ->and($guru->guru->kode_guru)->toBe('GB')
        ->and($guru->guru->status_aktif)->toBeTrue();

    $password = collect(session()->all())->dot()->first(fn ($v, $k) => str_ends_with($k, 'password.password'));
    auth()->logout();
    ($this->login)('guru.baru@contoh.test', $password);
    $this->assertAuthenticatedAs($guru);

    $this->actingAs($this->kepsek)->get('/les-ekskul/akun-staf')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/akun-staf')
        ->where('akun', fn ($a) => collect($a)->firstWhere('email', 'guru.baru@contoh.test')['kode_guru'] === 'GB'));
});

test('validasi: peran wajib, email unik, kode guru wajib & unik untuk peran Guru', function () {
    $tambah = fn (array $ubah) => $this->actingAs($this->kepsek)->post('/les-ekskul/akun-staf', [...$this->data, ...$ubah]);

    $tambah(['peran' => []])->assertSessionHasErrors('peran');
    $tambah(['email' => $this->tu->email])->assertSessionHasErrors('email');
    $tambah(['kode_guru' => ''])->assertSessionHasErrors('kode_guru');
    $tambah([])->assertSessionHasNoErrors();
    $tambah(['email' => 'lain@contoh.test', 'kode_guru' => 'GB'])->assertSessionHasErrors('kode_guru');
    $tambah(['email' => 'tu.baru@contoh.test', 'peran' => ['tu'], 'kode_guru' => ''])->assertSessionHasNoErrors(); // staf non-guru

    expect(User::where('email', 'tu.baru@contoh.test')->sole()->guru)->toBeNull();
});

test('ubah peran mempertahankan peran orang tua; Kepala Sekolah tidak bisa mencabut perannya sendiri', function () {
    $guru = User::factory()->create()->assignRole('guru', 'orang_tua');
    Guru::create(['user_id' => $guru->id, 'kode_guru' => 'X1']);

    $this->actingAs($this->kepsek)->put("/les-ekskul/akun-staf/{$guru->id}", ['nama' => 'Nama Baru', 'email' => $guru->email, 'peran' => ['guru', 'waka_kurikulum'], 'kode_guru' => 'X1'])
        ->assertSessionHasNoErrors();
    expect($guru->fresh()->getRoleNames()->sort()->values()->all())->toBe(['guru', 'orang_tua', 'waka_kurikulum'])
        ->and($guru->fresh()->name)->toBe('Nama Baru');

    $this->actingAs($this->kepsek)->put("/les-ekskul/akun-staf/{$this->kepsek->id}", ['nama' => 'Kepsek', 'email' => $this->kepsek->email, 'peran' => ['tu']])
        ->assertSessionHasErrors('peran');
    $this->actingAs($this->kepsek)->post("/les-ekskul/akun-staf/{$this->kepsek->id}/nonaktifkan")->assertSessionHasErrors('akun');

    // Akun orang tua murni bukan urusan halaman ini
    $ortu = User::factory()->create()->assignRole('orang_tua');
    $this->actingAs($this->kepsek)->post("/les-ekskul/akun-staf/{$ortu->id}/reset-password")->assertNotFound();
});

test('nonaktifkan: login ditolak, sesi diputus, guru ikut nonaktif, peringatan wali kelas; aktifkan kembali', function () {
    $user = User::factory()->create(['email' => 'wali@contoh.test'])->assignRole('guru');
    $guru = Guru::create(['user_id' => $user->id, 'kode_guru' => 'W1']);
    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 2, 'nama_kelas' => '2A', 'wali_kelas_id' => $guru->id]);
    DB::table('sessions')->insert(['id' => 'sesi-uji', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->kepsek)->post("/les-ekskul/akun-staf/{$user->id}/nonaktifkan")
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning');

    expect($user->fresh()->aktif)->toBeFalse()
        ->and($guru->fresh()->status_aktif)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    auth()->logout();
    ($this->login)('wali@contoh.test', 'password');
    $this->assertGuest();

    $this->actingAs($this->kepsek)->post("/les-ekskul/akun-staf/{$user->id}/aktifkan")->assertSessionHasNoErrors();
    expect($guru->fresh()->status_aktif)->toBeTrue();
    auth()->logout();
    ($this->login)('wali@contoh.test', 'password');
    $this->assertAuthenticatedAs($user);
});

test('reset password mengganti password lama; minimal satu Kepala Sekolah aktif', function () {
    $staf = User::factory()->create(['email' => 'staf@contoh.test'])->assignRole('tu');
    $this->actingAs($this->kepsek)->post("/les-ekskul/akun-staf/{$staf->id}/reset-password")->assertSessionHasNoErrors();

    auth()->logout();
    ($this->login)('staf@contoh.test', 'password');
    $this->assertGuest();

    // Kepala Sekolah satu-satunya tidak bisa dinonaktifkan oleh siapa pun
    expect(fn () => app(AkunStafService::class)->nonaktifkan($this->kepsek, $this->tu))
        ->toThrow(ValidationException::class);
    expect($this->kepsek->fresh()->aktif)->toBeTrue();
});
