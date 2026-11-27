# Panduan Demo Aplikasi SD Plus Gembala Baik

Panduan ini untuk uji coba dan demonstrasi aplikasi kepada Kepala Sekolah, Bendahara, Tata Usaha, orang tua, dan guru.
Datanya **data demo**: nama siswa, orang tua, dan pengajar eksternal fiktif, dan biaya kegiatan hanya perkiraan.
Data ini bukan data sekolah.

## 1. Menyiapkan data demo

Perintah berikut **mengosongkan seluruh database** (termasuk sesi login, jadi semua akun keluar), lalu mengisi ulang data demo:

```bash
php artisan migrate:fresh --seed --seeder=DataDemoSeeder
```

- Jalankan ulang sebelum setiap demo, supaya data kembali bersih walaupun sudah dicoba saat latihan.
- Lamanya: ±25 detik di Postgres lokal, ±25–30 menit di Supabase (±24.000 query, masing-masing ±65 ms pulang-pergi). Jangan membuka aplikasi selama proses berjalan.
- Tanggal "hari ini" di data = hari seeder dijalankan. Bila dijalankan sehari sebelum demo, laporan draft itu menjadi laporan kemarin, dan TU tinggal mengajukannya.
- Seeder dirancang untuk dijalankan **24 November – 22 Desember 2026** (periode laporan Desember). Di luar rentang itu seeder menolak berjalan.
- Riwayat Agustus sampai hari ini dibuat lewat alur aplikasi yang sama dengan pemakaian sungguhan, dengan jam yang disimulasikan. Karena itu angka tagihan, laporan, honor, dan kas saling cocok.
- Akun orang tua utama diambil dari `.env` (`DEMO_ORTU_NAMA`, `DEMO_ORTU_HP`, `DEMO_ORTU_EMAIL`, `DEMO_ORTU_PASSWORD`), sehingga data pribadi tidak tersimpan di repository. Bila kosong, dipakai akun fiktif `081200000100` / `password`.
- Supaya halaman pembayaran orang tua menampilkan rekening dan tombol WhatsApp TU, isi `REKENING_BANK`, `REKENING_NOMOR`, `REKENING_ATAS_NAMA`, dan `WA_TU` di `.env`.

## 2. Akun demo

Password akun staf dan orang tua fiktif: `password`.

| Peran | Login | Nama |
|---|---|---|
| Kepala Sekolah | `kepsek@sdplus.test` | Antonius Yayan |
| Tata Usaha | `serafina@sdplus.test` | Serafina Fuijun |
| Tata Usaha (admin IT) | `bryan@sdplus.test` | Bryan Henrilsen |
| Bendahara | `a@sdplus.test` | Angela Genoveva (juga guru & Waka Kurikulum) |
| Guru | `rb@sdplus.test`, `ay@sdplus.test`, `tk@sdplus.test`, `lyd@sdplus.test`, `ek@sdplus.test` | Ruben, Fransiska, Kartika, Lydia, Eky |
| Orang tua utama | no HP dari `.env` | anak: **Titi Tarihoran** (1A) dan **Theo Tarihoran** (4A) |
| Orang tua | `081200000101` | Yohana Wijaya, anak Nathan Wijaya (2B): **lunas sampai November** (bayar di muka) |
| Orang tua | `081200000102` | Hendra Gunawan, anak Clara Gunawan (3A): lunas, pernah **refund kelebihan transfer** |
| Orang tua | `081200000103` | Maria Lestari, anak Kevin Lestari (5B): **menunggak September–November**, konfirmasi pernah ditolak |

Akun guru lain: kode guru huruf kecil + `@sdplus.test` (mis. `dv@sdplus.test`).

Tips: setiap peran memakai jendela browser atau mode penyamaran sendiri, supaya tidak perlu keluar-masuk akun.

## 3. Keadaan data setelah seeder

| Periode laporan | Keadaan | Dipakai untuk demo |
|---|---|---|
| Agustus, September | Buku ditutup, honor dihitung, **Sudah Diketahui**, kas manual tercatat | Melihat riwayat lengkap, Laporan Kas |
| Oktober | Buku ditutup, honor dihitung, **belum diketahui** | Kepala Sekolah menandai "Sudah Diketahui" |
| November | Laporan bulanan **diajukan TU**, menunggu tutup buku | Bendahara menutup buku |
| Desember (berjalan, 23 Nov – 22 Des) | Laporan harian "kemarin" **menunggu Bendahara**, laporan hari ini **draft** | TU dan Bendahara harian |

Isi lainnya:
- **135 siswa** di 15 kelas.
- Les tambahan semua kelas, serta Basket, English Club (kelas paralel Starter/Mover/Flyer), Swimming, Coding, Mandarin Club, dan Olimpiade MTK.
- Kegiatan wajib otomatis: Bina Karakter (kelas 1–3) dan Pramuka (kelas 4–6).
- Pengajar dan pendamping lengkap. Contoh **pergantian pengajar Mandarin Club** di pertengahan Oktober (Kristina → Eky), sehingga honor Oktober dibagi per pertemuan.
- Penerima porsi pengelola: Kepala Sekolah (penanggung jawab), Angela (bendahara), Serafina (admin TU), Bryan (admin IT).
- Honor flat: Olimpiade, Bina Karakter, Pramuka, Pembuat Soal Les, dan petugas kebersihan (penerima tanpa akun).
- **Swimming tidak berlangsung November** (kolam dirawat), jadi tagihannya dibatalkan.
- Satu siswa berhenti Basket mulai Oktober.
- Satu laporan harian (11 November) pernah ditolak Bendahara lalu diajukan ulang.
- **Periode pendaftaran orang tua dibuka 23 November – 12 Desember** (semester genap).
- Hari ini ada transfer yang belum diverifikasi, dua konfirmasi "Saya sudah bayar" menunggu TU, dan satu refund kelebihan transfer (Rp25.000) belum dikembalikan.
- Forum Guru: 5 percakapan. Ruben dan Fransiska belum dibalas Kepala Sekolah, dan Kartika belum membaca pesan Kepala Sekolah.
- Jadwal Guru contoh (kisi-kisi dan jadwal mengajar), supaya **Jadwal Saya** guru tidak kosong.

## 4. Skenario demo per peran

Urutan yang disarankan: **orang tua → TU → Bendahara → Kepala Sekolah → guru dan Kepala Sekolah (forum)**.
Urutan ini mengikuti alur uang: orang tua membayar, TU mencatat, Bendahara mencocokkan dan menutup buku, lalu Kepala Sekolah mengetahui honornya.

### Orang tua (akun utama)
1. Masuk dengan **no HP** dan password. Beranda menampilkan dua anak: Titi dan Theo.
2. Buka **Titi**: tagihan Oktober dan November belum lunas. Riwayat memperlihatkan transfer Oktober yang **ditolak** beserta alasannya ("tidak ditemukan di mutasi rekening").
3. Bayar tagihan Titi:
   - centang tagihan;
   - lihat total, rekening sekolah, dan berita transfer;
   - tekan **"Saya sudah bayar"**, isi tanggal transfer dan nama pengirim.
4. Buka **Theo**: konfirmasi November berstatus **menunggu TU**. Nanti dicatat TU di langkah TU 2.
5. **Daftar kegiatan** (periode pendaftaran sedang dibuka):
   - daftarkan Titi ke **Mandarin Club** (kelas 1–3);
   - perhatikan jadwal, kuota, dan penolakan bila jadwal bentrok;
   - lihat kegiatan wajib Bina Karakter.
   - Catatan: tagihan kegiatan baru dibuat mulai **bulan pendaftaran** (FR-9). Mendaftar di akhir November berarti tagihan November ikut dibuat.

### Tata Usaha (`serafina@sdplus.test`)
1. **Beranda**: kartu tugas (transfer menunggu verifikasi, konfirmasi orang tua, refund).
2. **Konfirmasi Transfer**:
   - catat konfirmasi Theo (dan konfirmasi Titi dari langkah orang tua) menjadi transaksi;
   - tolak konfirmasi Kevin dengan alasan. Alasannya tampil di halaman Maria Lestari.
3. **Transaksi**:
   - verifikasi transfer hari ini yang masih pending;
   - catat pembayaran **cash** untuk siswa yang menunggak (mis. Kevin Lestari).
4. **Laporan Harian**:
   - lihat laporan hari ini, cetak laporan cash;
   - **ajukan** laporan ke Bendahara (setelah semua transfer diverifikasi).
5. **Refund**: kelebihan transfer Rp25.000 → **tandai dikembalikan**.
6. **Rekap Iuran**: tunggakan per kelas, kemudian **Unduh Excel**.
7. **Data Sekolah**: Data Siswa (impor Excel), Kelas & Les, dan Akun Orang Tua (klaim anak, reset password).

### Bendahara (`a@sdplus.test`)
1. **Laporan Harian**: laporan kemarin **diajukan**. Lakukan:
   - cocokkan transfer dengan mutasi rekening;
   - **Setujui semua transfer** (atau tolak satu transfer dengan alasan);
   - **setujui** laporan.
2. **Periode Laporan** November (diajukan TU): **Tutup Buku**. Honor November langsung dihitung otomatis.
3. **Laporan Kas**: kas sekolah dan kas yayasan Agustus–Oktober. Kas manual seperti saldo awal, uang kolam, ATK, admin bank, dan setoran yayasan sudah tercatat. Coba tambah kas manual November.
4. **Rekap Honor**: lihat hasil per kegiatan dan per orang.

### Kepala Sekolah (`kepsek@sdplus.test`)
1. **Beranda**: ringkasan dan kartu forum guru.
2. **Rekap Honor Oktober**:
   - honor per kegiatan, dengan **Mandarin Club dibagi per pertemuan** karena pengajarnya berganti;
   - honor flat (bisa diatur per periode), lalu rekap per orang dan cetak;
   - tandai **"Sudah Diketahui"**. Setelah itu honor dan kas Oktober terkunci.
3. **Laporan Kas**: saldo kas sekolah dan kas yayasan berantai sejak Agustus.
4. **Pengajar Ekskul / Skema Honor**: riwayat pergantian pengajar, persentase skema, penerima porsi, dan honor flat.
5. **Kegiatan Tidak Berlangsung**: contoh Swimming November.
6. **Forum Guru**: percakapan Ruben dan Fransiska belum dibaca. Balas salah satunya.

### Guru (forum)
1. Masuk `rb@sdplus.test` → **Forum Guru**: lihat pesan ke Kepala Sekolah, lalu kirim pesan baru. Pesan masih bisa dihapus selama 15 menit.
2. Masuk `tk@sdplus.test`: ada pesan Kepala Sekolah yang **belum dibaca** (lencana di menu).
3. **Jadwal Saya**: jadwal mengajar minggu ini dan kegiatan ekskul yang diajar/didampingi.

## 5. Setelah demo

Catat masukan klien, lalu jalankan ulang perintah di bagian 1 bila ingin mengulang demo dari awal.
