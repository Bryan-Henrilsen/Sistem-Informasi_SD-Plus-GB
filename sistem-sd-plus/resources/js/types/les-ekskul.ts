// Tipe data bersama halaman les & Ekskul

// Bentuk JSON paginator Laravel (->paginate()) yang dipakai halaman
export type Paginasi<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number|null;
    to: number|null;
    prev_page_url: string|null;
    next_page_url: string|null;
};

export type StatusTagihan = 'belum' | 'lunas' | 'dibatalkan';
export type StatusTransaksi = 'pending' | 'terverifikasi' | 'ditolak';
export type MetodeTransaksi = 'cash' | 'transfer' | 'online';
export type StatusPendaftaran = 'aktif' | 'berhenti' | 'pindah';
export type TipeKegiatan = 'les_tambahan' | 'ekstrakurikuler';

// Siswa ringkas untuk pencarian (Catat Pembayaran, Tambah Pendaftaran)
export type SiswaRingkas = { id: number; nama: string; kelas: string | null };


