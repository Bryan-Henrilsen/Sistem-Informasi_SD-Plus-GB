import React, { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import kisiKisiRoute from '@/routes/jadwal-guru/kisi-kisi';

type JenisTugasKode = 'utama' | 'damping' | 'piket';

interface TahunAjaran {
    id: number;
    nama: string;
}

interface GuruOption {
    id: number;
    kode_guru: string;
    nama: string;
    mapel_ids: number[]; // mapel yang diampu guru (Ringkasan v1.11 #47)
}

interface KelasOption {
    id: number;
    nama_kelas: string;
    nama_lengkap: string | null;
    jenjang: number;
    kuota_jam: number;
    teralokasi_jam: number;
}

interface MapelOption {
    id: number;
    kode: string;
    nama: string;
}

interface KisiKisiItem {
    id: number;
    guru_id: number;
    jenis_tugas_id: number;
    kelas_id: number | null;
    mapel_id: number | null;
    jumlah_jam: number;
    jadwal_terpasang: number;
    sisa_jam: number;
    guru: {
        id: number;
        kode_guru: string;
        nama: string;
    };
    kelas: {
        id: number;
        nama_kelas: string;
        jenjang: number;
    } | null;
    mata_pelajaran: {
        id: number;
        kode: string;
        nama: string;
    } | null;
    jenis_tugas: {
        id: number;
        kode: JenisTugasKode;
        nama: string;
    };
}

interface SummaryType {
    jumlah_alokasi: number;
    jumlah_jam: number;
}

interface KisiKisiTugasProps {
    tahunAjaran: TahunAjaran;
    guruList: GuruOption[];
    kelasList: KelasOption[];
    mapelList: MapelOption[];
    kisiKisiTugas: KisiKisiItem[];
    summary: {
        jumlah_alokasi: number;
        total_jam: number;
        per_jenis: Partial<Record<JenisTugasKode, SummaryType>>;
    };
}

interface AllocationForm {
    guru_id: string;
    jenis_tugas: JenisTugasKode;
    kelas_id: string;
    mapel_id: string;
    jumlah_jam: number;
}

const initialForm: AllocationForm = {
    guru_id: '',
    jenis_tugas: 'utama',
    kelas_id: '',
    mapel_id: '',
    jumlah_jam: 1,
};

const jenisTugasLabel: Record<JenisTugasKode, string> = {
    utama: 'Utama',
    damping: 'Damping',
    piket: 'Piket',
};

const jenisTugasStyle: Record<JenisTugasKode, string> = {
    utama: 'border-sekolah-info/30 bg-sekolah-info/10 text-sekolah-info',
    damping: 'border-sekolah-tertunda/30 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    piket: 'border-peran-ortu/30 bg-peran-ortu/10 text-peran-ortu',
};

export default function KisiKisiTugas({
    tahunAjaran,
    guruList,
    kelasList,
    mapelList,
    kisiKisiTugas,
    summary,
}: KisiKisiTugasProps) {
    const form = useForm<AllocationForm>(initialForm);
    const formErrors = form.errors as Record<string, string>;

    // Pilihan mapel = mapel yang diampu guru terpilih (Ringkasan v1.11 #47); guru tanpa data mapel tetap melihat semua mapel.
    // Mapel yang sedang dipilih tetap ditampilkan agar data lama bisa diubah
    const mapelGuru = useMemo(() => {
        const diampu = guruList.find((guru) => String(guru.id) === form.data.guru_id)?.mapel_ids ?? [];

        return diampu.length === 0
            ? mapelList
            : mapelList.filter((mapel) => diampu.includes(mapel.id) || String(mapel.id) === form.data.mapel_id);
    }, [guruList, mapelList, form.data.guru_id, form.data.mapel_id]);

    const [searchGuru, setSearchGuru] = useState('');
    const [filterType, setFilterType] = useState<Record<JenisTugasKode, boolean>>({
        utama: true,
        damping: true,
        piket: true,
    });
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<KisiKisiItem | null>(null);
    // Pesan berhasil dikirim server lewat Inertia::flash('toast') dan ditampilkan Toaster global; pesan gagal memakai toast.error

    const mainAllocations = useMemo(
        () => kisiKisiTugas.filter((item) => item.jenis_tugas.kode === 'utama'),
        [kisiKisiTugas],
    );
    const dampingAllocations = useMemo(
        () => kisiKisiTugas.filter((item) => item.jenis_tugas.kode === 'damping'),
        [kisiKisiTugas],
    );
    const piketAllocations = useMemo(
        () => kisiKisiTugas.filter((item) => item.jenis_tugas.kode === 'piket'),
        [kisiKisiTugas],
    );

    const visibleTeachers = useMemo(() => {
        if (!filterType.utama) return [];

        const term = searchGuru.trim().toLowerCase();
        return guruList.filter((guru) => {
            const teacherMainAllocations = mainAllocations.filter((item) => item.guru_id === guru.id);
            const matchesSearch = !term ||
                guru.nama.toLowerCase().includes(term) ||
                guru.kode_guru.toLowerCase().includes(term) ||
                teacherMainAllocations.some((item) =>
                    item.mata_pelajaran?.nama.toLowerCase().includes(term) ||
                    item.mata_pelajaran?.kode.toLowerCase().includes(term),
                );
            return matchesSearch && teacherMainAllocations.length > 0;
        });
    }, [guruList, mainAllocations, searchGuru, filterType.utama]);

    const openCreateModal = () => {
        setEditingItem(null);
        form.reset();
        form.clearErrors();
        form.setData({ ...initialForm, guru_id: guruList[0] ? String(guruList[0].id) : '' });
        setIsModalOpen(true);
    };

    const openEditModal = (item: KisiKisiItem) => {
        setEditingItem(item);
        form.clearErrors();
        form.setData({
            guru_id: String(item.guru_id),
            jenis_tugas: item.jenis_tugas.kode,
            kelas_id: item.kelas_id ? String(item.kelas_id) : '',
            mapel_id: item.mapel_id ? String(item.mapel_id) : '',
            jumlah_jam: item.jumlah_jam,
        });
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingItem(null);
        form.reset();
        form.clearErrors();
    };

    const submitForm = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const payload = {
            ...form.data,
            kelas_id: form.data.jenis_tugas === 'utama' ? form.data.kelas_id : '',
            mapel_id: form.data.jenis_tugas === 'utama' ? form.data.mapel_id : '',
        };

        form.setData(payload);

        if (editingItem) {
            form.put(`/jadwal-guru/kisi-kisi/${editingItem.id}`, {
                preserveScroll: true,
                onSuccess: closeModal,
            });
        } else {
            form.post('/jadwal-guru/kisi-kisi', {
                preserveScroll: true,
                onSuccess: closeModal,
            });
        }
    };

    const deleteAllocation = (item: KisiKisiItem) => {
        const label = item.jenis_tugas.kode === 'utama'
            ? `${item.mata_pelajaran?.nama ?? 'Mata pelajaran'} kelas ${item.kelas?.nama_kelas ?? ''}`
            : `${jenisTugasLabel[item.jenis_tugas.kode]} ${item.guru.nama}`;

        if (!window.confirm(`Hapus alokasi ${label}?`)) return;

        router.delete(`/jadwal-guru/kisi-kisi/${item.id}`, {
            preserveScroll: true,
            onError: (errors) => {
                const message = errors.allocation || Object.values(errors)[0] || 'Alokasi gagal dihapus.';
                toast.error(message);
            },
        });
    };

    const allocationsForCell = (guruId: number, kelasId: number) =>
        mainAllocations.filter((item) => item.guru_id === guruId && item.kelas_id === kelasId);

    const totalJamGuru = (guruId: number) =>
        kisiKisiTugas
            .filter((item) => item.guru_id === guruId)
            .reduce((total, item) => total + item.jumlah_jam, 0);

    const renderSpecialAllocations = (items: KisiKisiItem[], type: 'damping' | 'piket') => {
        if (!filterType[type]) return null;

        return (
            <section className="rounded-xl border border-border bg-card shadow-sm overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                    <div>
                        <h3 className="font-bold text-foreground">Alokasi {jenisTugasLabel[type]} Tanpa Kelas</h3>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {type === 'damping'
                                ? 'Penugasan pendamping per guru. Kelas dan pasangan ditentukan saat pembagian jadwal.'
                                : 'Jumlah jam piket per guru. Penempatan dilakukan langsung pada slot waktu.'}
                        </p>
                    </div>
                    <span className={`rounded-full border px-3 py-1 text-xs font-bold ${jenisTugasStyle[type]}`}>
                        {items.length} alokasi · {items.reduce((total, item) => total + item.jumlah_jam, 0)} jam
                    </span>
                </div>
                <div className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    {items.map((item) => (
                        <div key={item.id} className="rounded-lg border border-border p-3 transition hover:border-ring hover:shadow-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-semibold text-sm text-foreground">{item.guru.nama}</div>
                                    <div className="text-[11px] text-muted-foreground">{item.guru.kode_guru}</div>
                                </div>
                                <span className={`rounded px-2 py-1 text-[10px] font-bold uppercase ${jenisTugasStyle[type]}`}>
                                    {jenisTugasLabel[type]}
                                </span>
                            </div>
                            <div className="mt-3 flex items-end justify-between border-t border-border pt-2">
                                <div>
                                    <div className="text-[11px] text-muted-foreground">Alokasi per minggu</div>
                                    <div className="text-lg font-bold text-foreground">{item.jumlah_jam} jam</div>
                                </div>
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => openEditModal(item)} className="text-xs font-semibold text-foreground hover:underline">Edit</button>
                                    <button type="button" onClick={() => deleteAllocation(item)} className="text-xs font-semibold text-sekolah-bahaya hover:underline">Hapus</button>
                                </div>
                            </div>
                        </div>
                    ))}
                    {items.length === 0 && (
                        <p className="col-span-full rounded-lg border border-dashed border-border p-5 text-center text-sm text-muted-foreground">
                            Belum ada alokasi {jenisTugasLabel[type].toLowerCase()}.
                        </p>
                    )}
                </div>
            </section>
        );
    };

    return (
        <>
            <Head title="Kisi-Kisi Tugas" />

            <div className="flex text-foreground">

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="shrink-0 border-b border-border bg-card px-6 py-5 shadow-sm lg:px-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold text-foreground">Matriks Penugasan Guru</h2>
                                <p className="mt-1 text-sm text-muted-foreground">Tahun Ajaran: {tahunAjaran.nama}</p>
                            </div>
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90"
                            >
                                + Tambah Manual
                            </button>
                        </div>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-lg border border-border bg-muted/50 p-3">
                                <div className="text-xs text-muted-foreground">Total alokasi</div>
                                <div className="mt-1 text-2xl font-bold">{summary.jumlah_alokasi}</div>
                                <div className="text-[11px] text-muted-foreground">Baris kisi-kisi</div>
                            </div>
                            <div className="rounded-lg border border-border bg-muted/50 p-3">
                                <div className="text-xs text-muted-foreground">Total beban tugas</div>
                                <div className="mt-1 text-2xl font-bold">{summary.total_jam.toLocaleString('id-ID')}</div>
                                <div className="text-[11px] text-muted-foreground">Jam per minggu</div>
                            </div>
                            <div className="rounded-lg border border-sekolah-info/20 bg-sekolah-info/10 p-3">
                                <div className="text-xs text-sekolah-info">Tugas utama</div>
                                <div className="mt-1 text-2xl font-bold text-sekolah-info">{summary.per_jenis.utama?.jumlah_jam ?? 0}</div>
                                <div className="text-[11px] text-sekolah-info">{summary.per_jenis.utama?.jumlah_alokasi ?? 0} alokasi</div>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="rounded-lg border border-sekolah-tertunda/20 bg-sekolah-tertunda/10 p-3">
                                    <div className="text-xs text-sekolah-tertunda">Damping</div>
                                    <div className="mt-1 text-xl font-bold text-sekolah-tertunda">{summary.per_jenis.damping?.jumlah_jam ?? 0}</div>
                                    <div className="text-[11px] text-sekolah-tertunda">jam</div>
                                </div>
                                <div className="rounded-lg border border-peran-ortu/20 bg-peran-ortu/10 p-3">
                                    <div className="text-xs text-peran-ortu">Piket</div>
                                    <div className="mt-1 text-xl font-bold text-peran-ortu">{summary.per_jenis.piket?.jumlah_jam ?? 0}</div>
                                    <div className="text-[11px] text-peran-ortu">jam</div>
                                </div>
                            </div>
                        </div>
                    </header>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-card px-6 py-3 lg:px-8">
                        <div className="flex flex-wrap items-center gap-2 text-xs">
                            {(['utama', 'damping', 'piket'] as JenisTugasKode[]).map((type) => (
                                <label key={type} className="flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-muted-foreground hover:bg-muted/50">
                                    <input
                                        type="checkbox"
                                        checked={filterType[type]}
                                        onChange={() => setFilterType((current) => ({ ...current, [type]: !current[type] }))}
                                        className="rounded border-input text-foreground focus:ring-ring/50"
                                    />
                                    {jenisTugasLabel[type]}
                                </label>
                            ))}
                        </div>
                        <div className="flex items-center gap-2">
                            <input
                                type="search"
                                value={searchGuru}
                                onChange={(event: React.ChangeEvent<HTMLInputElement>) => setSearchGuru(event.target.value)}
                                placeholder="Cari nama, kode guru, atau mapel..."
                                className="w-72 max-w-full rounded-md border border-input px-3 py-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/50"
                            />
                        </div>
                    </div>

                    <div className="flex-1 space-y-6 overflow-auto p-4 lg:p-6">
                        {filterType.utama && (
                            <section className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-5 py-4">
                                    <div>
                                        <h3 className="font-bold text-foreground">Matriks Guru × Kelas</h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">Beban mengajar utama per mata pelajaran dan kelas.</p>
                                    </div>
                                    <span className="text-xs text-muted-foreground">Geser tabel ke kanan untuk melihat kelas lainnya</span>
                                </div>
                                <div className="max-h-[68vh] overflow-auto">
                                    <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                                        <thead>
                                            <tr>
                                                <th className="sticky left-0 top-0 z-40 min-w-[180px] border-b border-r border-border bg-muted px-4 py-3 text-left font-bold text-foreground">Nama Guru</th>
                                                <th className="sticky top-0 z-30 min-w-[80px] border-b border-r border-border bg-muted px-3 py-3 text-center font-bold text-foreground">Kode</th>
                                                <th className="sticky top-0 z-30 min-w-[90px] border-b border-r border-border bg-muted px-3 py-3 text-center font-bold text-foreground">Total Jam</th>
                                                {kelasList.map((kelas) => {
                                                    const penuh = kelas.teralokasi_jam >= kelas.kuota_jam;
                                                    return (
                                                        <th key={kelas.id} className="sticky top-0 z-30 min-w-[150px] border-b border-r border-border bg-card px-3 py-3 text-center">
                                                            <div className="font-bold text-foreground">{kelas.nama_kelas}</div>
                                                            <div className="mt-0.5 text-[10px] text-muted-foreground">Jenjang {kelas.jenjang}</div>
                                                            <div className={`mt-1 text-xs font-semibold ${penuh ? 'text-sekolah-sukses' : 'text-foreground'}`}>
                                                                {kelas.teralokasi_jam}/{kelas.kuota_jam} jam
                                                            </div>
                                                        </th>
                                                    );
                                                })}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {visibleTeachers.map((guru) => {
                                                const teacherItems = kisiKisiTugas.filter((item) => item.guru_id === guru.id);
                                                const mainItems = teacherItems.filter((item) => item.jenis_tugas.kode === 'utama');
                                                if (mainItems.length === 0) return null;

                                                return (
                                                    <tr key={guru.id} className="group hover:bg-muted/50">
                                                        <td className="sticky left-0 z-20 border-b border-r border-border bg-card px-4 py-3 align-top group-hover:bg-muted">
                                                            <div className="font-semibold text-foreground">{guru.nama}</div>
                                                            <div className="mt-0.5 text-[10px] text-muted-foreground">Guru</div>
                                                        </td>
                                                        <td className="border-b border-r border-border px-3 py-3 text-center align-top text-muted-foreground">{guru.kode_guru}</td>
                                                        <td className="border-b border-r border-border px-3 py-3 text-center align-top">
                                                            <span className="font-bold text-foreground">{totalJamGuru(guru.id)}</span>
                                                        </td>
                                                        {kelasList.map((kelas) => {
                                                            const items = allocationsForCell(guru.id, kelas.id);
                                                            return (
                                                                <td key={kelas.id} className="min-w-[150px] border-b border-r border-border p-2 align-top">
                                                                    <div className="space-y-1.5">
                                                                        {items.map((item) => (
                                                                            <button
                                                                                key={item.id}
                                                                                type="button"
                                                                                onClick={() => openEditModal(item)}
                                                                                className="flex w-full items-center justify-between gap-2 rounded border border-sekolah-info/20 bg-sekolah-info/10 px-2 py-1.5 text-left transition hover:border-sekolah-info/60 hover:bg-sekolah-info/15"
                                                                                title="Klik untuk mengubah alokasi"
                                                                            >
                                                                                <span className="min-w-0 truncate text-xs font-medium text-sekolah-info">
                                                                                    {item.mata_pelajaran?.kode ?? item.mata_pelajaran?.nama ?? 'Mapel'}
                                                                                </span>
                                                                                <span className="shrink-0 text-xs font-bold text-sekolah-info">{item.jumlah_jam}</span>
                                                                            </button>
                                                                        ))}
                                                                        {items.length === 0 && <span className="text-xs text-muted-foreground/50">—</span>}
                                                                    </div>
                                                                </td>
                                                            );
                                                        })}
                                                    </tr>
                                                );
                                            })}
                                            {visibleTeachers.every((guru) => !mainAllocations.some((item) => item.guru_id === guru.id)) && (
                                                <tr>
                                                    <td colSpan={kelasList.length + 3} className="px-6 py-10 text-center text-sm text-muted-foreground">
                                                        Tidak ada alokasi utama yang cocok dengan pencarian atau filter.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}

                        {renderSpecialAllocations(
                            dampingAllocations.filter((item) => {
                                const term = searchGuru.trim().toLowerCase();
                                return !term || item.guru.nama.toLowerCase().includes(term) || item.guru.kode_guru.toLowerCase().includes(term);
                            }),
                            'damping',
                        )}
                        {renderSpecialAllocations(
                            piketAllocations.filter((item) => {
                                const term = searchGuru.trim().toLowerCase();
                                return !term || item.guru.nama.toLowerCase().includes(term) || item.guru.kode_guru.toLowerCase().includes(term);
                            }),
                            'piket',
                        )}
                    </div>
                </main>
            </div>

            {isModalOpen && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto bg-black/40 p-4 backdrop-blur-[2px]">
                    <div className="my-8 w-full max-w-xl overflow-hidden rounded-xl bg-card text-foreground shadow-2xl">
                        <div className="flex items-center justify-between border-b border-border bg-muted/50 px-6 py-4">
                            <div>
                                <h3 className="font-bold text-foreground">{editingItem ? 'Ubah Alokasi Tugas' : 'Tambah Alokasi Tugas'}</h3>
                                <p className="mt-0.5 text-xs text-muted-foreground">Tahun Ajaran {tahunAjaran.nama}</p>
                            </div>
                            <button type="button" onClick={closeModal} className="text-2xl leading-none text-muted-foreground hover:text-foreground">&times;</button>
                        </div>

                        <form onSubmit={submitForm} className="space-y-4 p-6">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-foreground">Guru</label>
                                <select
                                    value={form.data.guru_id}
                                    onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('guru_id', event.target.value)}
                                    disabled={Boolean(editingItem?.jadwal_terpasang)}
                                    className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-ring disabled:bg-muted"
                                >
                                    <option value="">Pilih guru</option>
                                    {guruList.map((guru) => <option key={guru.id} value={guru.id}>{guru.nama} ({guru.kode_guru})</option>)}
                                </select>
                                {form.errors.guru_id && <p className="mt-1 text-xs text-sekolah-bahaya">{form.errors.guru_id}</p>}
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-foreground">Jenis Tugas</label>
                                <select
                                    value={form.data.jenis_tugas}
                                    onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData({
                                        ...form.data,
                                        jenis_tugas: event.target.value as JenisTugasKode,
                                        kelas_id: '',
                                        mapel_id: '',
                                    })}
                                    disabled={Boolean(editingItem?.jadwal_terpasang)}
                                    className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-ring disabled:bg-muted"
                                >
                                    <option value="utama">Mengajar Utama</option>
                                    <option value="damping">Guru Damping</option>
                                    <option value="piket">Tugas Piket</option>
                                </select>
                                {form.errors.jenis_tugas && <p className="mt-1 text-xs text-sekolah-bahaya">{form.errors.jenis_tugas}</p>}
                            </div>

                            {form.data.jenis_tugas === 'utama' ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-foreground">Kelas</label>
                                        <select
                                            value={form.data.kelas_id}
                                            onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('kelas_id', event.target.value)}
                                            disabled={Boolean(editingItem?.jadwal_terpasang)}
                                            className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-ring disabled:bg-muted"
                                        >
                                            <option value="">Pilih kelas</option>
                                            {kelasList.map((kelas) => (
                                                <option key={kelas.id} value={kelas.id}>
                                                    {kelas.nama_lengkap || kelas.nama_kelas} · Kuota {kelas.kuota_jam} jam
                                                </option>
                                            ))}
                                        </select>
                                        {form.errors.kelas_id && <p className="mt-1 text-xs text-sekolah-bahaya">{form.errors.kelas_id}</p>}
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-foreground">Mata Pelajaran</label>
                                        <select
                                            value={form.data.mapel_id}
                                            onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('mapel_id', event.target.value)}
                                            disabled={Boolean(editingItem?.jadwal_terpasang)}
                                            className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:border-ring disabled:bg-muted"
                                        >
                                            <option value="">Pilih mata pelajaran</option>
                                            {mapelGuru.map((mapel) => <option key={mapel.id} value={mapel.id}>{mapel.nama} ({mapel.kode})</option>)}
                                        </select>
                                        {mapelGuru.length === mapelList.length && form.data.guru_id && (
                                            <p className="mt-1 text-xs text-muted-foreground">Guru ini belum punya data mapel yang diampu; semua mapel ditampilkan.</p>
                                        )}
                                        {form.errors.mapel_id && <p className="mt-1 text-xs text-sekolah-bahaya">{form.errors.mapel_id}</p>}
                                    </div>
                                </div>
                            ) : (
                                <div className="rounded-md border border-dashed border-input bg-muted/50 px-4 py-3 text-xs leading-relaxed text-muted-foreground">
                                    Alokasi {jenisTugasLabel[form.data.jenis_tugas].toLowerCase()} tidak terikat pada kelas maupun mata pelajaran. Pasangan guru damping ditentukan saat pembagian jadwal, bukan di halaman ini.
                                </div>
                            )}

                            <div>
                                <label className="mb-1 block text-sm font-medium text-foreground">Jumlah Jam per Minggu</label>
                                <input
                                    type="number"
                                    min={1}
                                    max={53}
                                    value={form.data.jumlah_jam}
                                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => form.setData('jumlah_jam', Number(event.target.value))}
                                    className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring"
                                />
                                {form.errors.jumlah_jam && <p className="mt-1 text-xs text-sekolah-bahaya">{form.errors.jumlah_jam}</p>}
                            </div>

                            {formErrors.allocation && <p className="rounded-md bg-sekolah-bahaya/10 px-3 py-2 text-xs text-sekolah-bahaya">{formErrors.allocation}</p>}

                            <div className="flex justify-end gap-2 border-t border-border pt-4">
                                <button type="button" onClick={closeModal} className="rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground hover:bg-muted/50">Batal</button>
                                <button type="submit" disabled={form.processing} className="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60">
                                    {form.processing ? 'Menyimpan...' : editingItem ? 'Simpan Perubahan' : 'Simpan Alokasi'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );
}

// Breadcrumb di header layout aplikasi (Ringkasan v1.11 #52)
KisiKisiTugas.layout = {
    breadcrumbs: [{ title: 'Kisi-Kisi Tugas', href: kisiKisiRoute.index() }],
};
