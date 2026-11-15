<?php

use App\Domains\LesEkskul\Services\AkunOrangTuaService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\NomorHp;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tu = User::factory()->create()->assignRole('tu');
    $lalu = TahunAjaran::create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-30', 'status_aktif' => false]);
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->kelas2A = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    $this->kelasLalu = Kelas::create(['tahun_ajaran_id' => $lalu->id, 'jenjang' => 6, 'nama_kelas' => '6A']);

    $this->budi = Siswa::create(['nama' => 'Budi Fiktif']);
    SiswaKelas::create(['siswa_id' => $this->budi->id, 'kelas_id' => $this->kelas2A->id]);
    $this->alumni = Siswa::create(['nama' => 'Alumni Fiktif']);
    SiswaKelas::create(['siswa_id' => $this->alumni->id, 'kelas_id' => $this->kelasLalu->id]);
});

function otDaftar(array $data = [])
{
    return test()->post('/register', [
        'name' => 'Ibu Fiktif', 'no_hp' => '+62 812-3456-7890', 'password' => 'password', 'password_confirmation' => 'password', ...$data,
    ]);
}

function otOrangTua(string $noHp = '081234567890'): User
{
    return User::factory()->create(['email' => null, 'no_hp' => $noHp])->assignRole('orang_tua');
}

test('nomor HP dinormalkan ke bentuk 08…', function () {
    expect(NomorHp::normal('+62 812-3456-7890'))->toBe('081234567890')
        ->and(NomorHp::normal('6281234567890'))->toBe('081234567890')
        ->and(NomorHp::normal('812 3456 7890'))->toBe('081234567890')
        ->and(NomorHp::normal('0812'))->toBeNull()
        ->and(NomorHp::normal('021 555 1234'))->toBeNull();
});

test('orang tua mendaftar dengan no HP tanpa email dan langsung menjadi orang tua', function () {
    otDaftar()->assertRedirect('/dashboard');

    $user = User::where('no_hp', '081234567890')->firstOrFail();
    expect($user->email)->toBeNull()
        ->and($user->getRoleNames()->all())->toBe(['orang_tua']);

    // Beranda staf mengarahkan orang tua ke beranda orang tua
    $this->get('/dashboard')->assertRedirect('/ortu');
});

test('no HP yang sama dalam bentuk lain ditolak; no HP tidak valid ditolak', function () {
    otOrangTua();

    otDaftar(['no_hp' => '0812 3456 7890'])->assertSessionHasErrors(['no_hp' => 'Nomor HP ini sudah terdaftar. Silakan login, atau hubungi TU bila lupa password.']);
    otDaftar(['no_hp' => '12345'])->assertSessionHasErrors('no_hp');
    $this->assertGuest();
});

test('login memakai no HP dalam bentuk apa pun; staf tetap memakai email', function () {
    $ortu = otOrangTua();

    $this->post('/login', ['login' => '+62 812 3456 7890', 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($ortu);
    $this->post('/logout');

    $this->post('/login', ['login' => $this->tu->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($this->tu);
    $this->post('/logout');

    $this->post('/login', ['login' => '081234567890', 'password' => 'salah'])->assertSessionHasErrors('login');
});

test('orang tua mengklaim anak dari kelas tahun aktif; ayah dan ibu boleh mengklaim anak yang sama', function () {
    $ibu = otOrangTua();
    $ayah = otOrangTua('081299998888');

    $this->actingAs($ibu)->get('/ortu/anak')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/orang-tua/anak')
        ->has('kelas', 1) // hanya kelas tahun ajaran aktif
        ->where('kelas.0.siswa.0.nama', 'Budi Fiktif')
    );

    $this->actingAs($ibu)->post('/ortu/anak', ['siswa_id' => $this->budi->id])->assertSessionHasNoErrors();
    $this->actingAs($ibu)->post('/ortu/anak', ['siswa_id' => $this->budi->id])->assertSessionHasErrors('siswa_id');
    $this->actingAs($ibu)->post('/ortu/anak', ['siswa_id' => $this->alumni->id])->assertSessionHasErrors('siswa_id');
    $this->actingAs($ayah)->post('/ortu/anak', ['siswa_id' => $this->budi->id])->assertSessionHasNoErrors();

    expect($this->budi->orangTua()->pluck('users.id')->sort()->values()->all())->toBe(collect([$ibu->id, $ayah->id])->sort()->values()->all());

    $this->actingAs($ibu)->get('/ortu')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/orang-tua/beranda')
        ->where('anak.0.nama', 'Budi Fiktif')
        ->where('anak.0.kelas', '2A')
    );

    $this->actingAs($ibu)->delete("/ortu/anak/{$this->budi->id}")->assertSessionHasNoErrors();
    expect($ibu->anak()->count())->toBe(0)->and($ayah->anak()->count())->toBe(1);
});

test('orang tua mengubah no HP dan email di halaman Akun', function () {
    $ortu = otOrangTua();

    $this->actingAs($ortu)->patch('/settings/profile', ['name' => 'Ibu Baru', 'no_hp' => '62 811 2222 3333', 'email' => 'ibu@contoh.test'])
        ->assertSessionHasNoErrors();
    expect($ortu->fresh()->only(['name', 'no_hp', 'email']))->toBe(['name' => 'Ibu Baru', 'no_hp' => '081122223333', 'email' => 'ibu@contoh.test']);

    $this->actingAs($ortu)->patch('/settings/profile', ['name' => 'Ibu Baru', 'no_hp' => '081122223333', 'email' => ''])->assertSessionHasNoErrors();
    expect($ortu->fresh()->email)->toBeNull();
});

test('TU membuat password sementara, menghapus klaim, dan menambah siswa', function () {
    $ortu = otOrangTua();
    $ortu->anak()->attach($this->budi->id);

    $this->actingAs($this->tu)->post("/les-ekskul/akun-orang-tua/{$ortu->id}/reset-password")->assertSessionHas('password_baru');
    $password = session('password_baru')['password'];
    $this->post('/logout');
    $this->post('/login', ['login' => '081234567890', 'password' => $password]);
    $this->assertAuthenticatedAs($ortu);
    $this->post('/logout');

    // Bukan untuk akun staf
    expect(fn () => app(AkunOrangTuaService::class)->resetPassword($this->tu))->toThrow(ValidationException::class);

    $this->actingAs($this->tu)->delete("/les-ekskul/akun-orang-tua/{$ortu->id}/anak/{$this->budi->id}")->assertSessionHasNoErrors();
    expect($ortu->anak()->count())->toBe(0);

    $this->actingAs($this->tu)->post('/les-ekskul/siswa', ['nama' => 'Murid Pindahan', 'kelas_id' => $this->kelas2A->id])->assertSessionHasNoErrors();
    $this->actingAs($this->tu)->post('/les-ekskul/siswa', ['nama' => 'murid  pindahan', 'kelas_id' => $this->kelas2A->id])->assertSessionHasErrors('nama');
    $this->actingAs($this->tu)->post('/les-ekskul/siswa', ['nama' => 'Murid Lain', 'kelas_id' => $this->kelasLalu->id])->assertSessionHasErrors('kelas_id');
    expect(SiswaKelas::where('kelas_id', $this->kelas2A->id)->count())->toBe(2);

    $this->actingAs($this->tu)->get('/les-ekskul/akun-orang-tua?cari=3456')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/akun-orang-tua')
        ->where('orangTua.total', 1)
    );
});

test('hak akses: orang tua tidak membuka halaman staf, staf tidak membuka halaman orang tua', function () {
    $ortu = otOrangTua();
    $bendahara = User::factory()->create()->assignRole('bendahara');

    $this->actingAs($ortu)->get('/les-ekskul/tagihan')->assertForbidden();
    $this->actingAs($this->tu)->get('/ortu')->assertForbidden();
    $this->actingAs($bendahara)->get('/les-ekskul/akun-orang-tua')->assertForbidden();
});
