import React, { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type JenisTugasKode = 'utama' | 'damping' | 'piket';

interface TahunAjaran {
    id: number;
    nama: string;
}

interface GuruOption {
    id: number;
    kode_guru: string;
    nama: string;
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

interface PageProps {
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
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
    utama: 'border-blue-200 bg-blue-50 text-blue-800',
    damping: 'border-orange-200 bg-orange-50 text-orange-800',
    piket: 'border-purple-200 bg-purple-50 text-purple-800',
};

export default function KisiKisiTugas({
    tahunAjaran,
    guruList,
    kelasList,
    mapelList,
    kisiKisiTugas,
    summary,
}: KisiKisiTugasProps) {
    const { flash } = usePage<PageProps>().props;
    const form = useForm<AllocationForm>(initialForm);
    const formErrors = form.errors as Record<string, string>;

    const [searchGuru, setSearchGuru] = useState('');
    const [filterType, setFilterType] = useState<Record<JenisTugasKode, boolean>>({
        utama: true,
        damping: true,
        piket: true,
    });
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<KisiKisiItem | null>(null);
    const [toast, setToast] = useState<{ show: boolean; message: string; type: 'success' | 'error' }>({
        show: false,
        message: '',
        type: 'success',
    });

    useEffect(() => {
        if (flash?.success) {
            setToast({ show: true, message: flash.success, type: 'success' });
            const timer = window.setTimeout(() => {
                setToast({ show: false, message: '', type: 'success' });
            }, 3500);
            return () => window.clearTimeout(timer);
        }

        if (flash?.error) {
            setToast({ show: true, message: flash.error, type: 'error' });
            const timer = window.setTimeout(() => {
                setToast({ show: false, message: '', type: 'error' });
            }, 4500);
            return () => window.clearTimeout(timer);
        }
    }, [flash]);

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
                setToast({ show: true, message, type: 'error' });
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
            <section className="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                    <div>
                        <h3 className="font-bold text-[#3A322C]">Alokasi {jenisTugasLabel[type]} Tanpa Kelas</h3>
                        <p className="mt-0.5 text-xs text-gray-500">
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
                        <div key={item.id} className="rounded-lg border border-gray-200 p-3 transition hover:border-[#A9784F]/50 hover:shadow-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-semibold text-sm text-[#3A322C]">{item.guru.nama}</div>
                                    <div className="text-[11px] text-gray-500">{item.guru.kode_guru}</div>
                                </div>
                                <span className={`rounded px-2 py-1 text-[10px] font-bold uppercase ${jenisTugasStyle[type]}`}>
                                    {jenisTugasLabel[type]}
                                </span>
                            </div>
                            <div className="mt-3 flex items-end justify-between border-t border-gray-100 pt-2">
                                <div>
                                    <div className="text-[11px] text-gray-500">Alokasi per minggu</div>
                                    <div className="text-lg font-bold text-[#A9784F]">{item.jumlah_jam} jam</div>
                                </div>
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => openEditModal(item)} className="text-xs font-semibold text-[#7A5738] hover:underline">Edit</button>
                                    <button type="button" onClick={() => deleteAllocation(item)} className="text-xs font-semibold text-red-600 hover:underline">Hapus</button>
                                </div>
                            </div>
                        </div>
                    ))}
                    {items.length === 0 && (
                        <p className="col-span-full rounded-lg border border-dashed border-gray-200 p-5 text-center text-sm text-gray-400">
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

            {toast.show && (
                <div className={`fixed left-1/2 top-5 z-[70] -translate-x-1/2 rounded-lg border px-5 py-3 text-sm font-medium shadow-lg ${
                    toast.type === 'error'
                        ? 'border-red-200 bg-red-50 text-red-800'
                        : 'border-green-200 bg-green-50 text-green-800'
                }`}>
                    {toast.message}
                </div>
            )}

            <div className="flex min-h-screen bg-[#FAF7F2] font-sans text-[#3A322C]">
                <aside className="z-20 flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white shadow-sm">
                    <div className="border-b border-gray-200 p-6">
                        <h1 className="text-xl font-bold leading-tight text-[#7A5738]">SD Plus<br />Gembala Baik</h1>
                        <p className="mt-1 text-xs text-gray-500">Manajemen Akademik</p>
                    </div>
                    <nav className="flex-1 space-y-1 overflow-y-auto p-4">
                        <div className="mb-2 mt-6 text-xs font-bold uppercase tracking-wider text-gray-400">Modul Jadwal Guru</div>
                        <a href="/jadwal-guru/waktu-pelajaran" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Waktu Pelajaran</a>
                        <a href="/jadwal-guru/kisi-kisi" className="block rounded-md border-l-4 border-[#A9784F] bg-[#A9784F]/10 px-3 py-2 text-sm font-medium text-[#7A5738]">Kisi-Kisi Tugas</a>
                        <a href="/jadwal-guru/pembagian" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Pembagian Jadwal</a>
                        <a href="/jadwal-guru/hasil" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Hasil Jadwal</a>
                    </nav>
                </aside>

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="shrink-0 border-b border-gray-200 bg-white px-6 py-5 shadow-sm lg:px-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold text-[#7A5738]">Matriks Penugasan Guru</h2>
                                <p className="mt-1 text-sm text-gray-500">Tahun Ajaran: {tahunAjaran.nama}</p>
                            </div>
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md bg-[#A9784F] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#7A5738]"
                            >
                                + Tambah Manual
                            </button>
                        </div>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <div className="text-xs text-gray-500">Total alokasi</div>
                                <div className="mt-1 text-2xl font-bold">{summary.jumlah_alokasi}</div>
                                <div className="text-[11px] text-gray-400">Baris kisi-kisi</div>
                            </div>
                            <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                <div className="text-xs text-gray-500">Total beban tugas</div>
                                <div className="mt-1 text-2xl font-bold">{summary.total_jam.toLocaleString('id-ID')}</div>
                                <div className="text-[11px] text-gray-400">Jam per minggu</div>
                            </div>
                            <div className="rounded-lg border border-blue-100 bg-blue-50/70 p-3">
                                <div className="text-xs text-blue-700">Tugas utama</div>
                                <div className="mt-1 text-2xl font-bold text-blue-900">{summary.per_jenis.utama?.jumlah_jam ?? 0}</div>
                                <div className="text-[11px] text-blue-600">{summary.per_jenis.utama?.jumlah_alokasi ?? 0} alokasi</div>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="rounded-lg border border-orange-100 bg-orange-50/70 p-3">
                                    <div className="text-xs text-orange-700">Damping</div>
                                    <div className="mt-1 text-xl font-bold text-orange-900">{summary.per_jenis.damping?.jumlah_jam ?? 0}</div>
                                    <div className="text-[11px] text-orange-600">jam</div>
                                </div>
                                <div className="rounded-lg border border-purple-100 bg-purple-50/70 p-3">
                                    <div className="text-xs text-purple-700">Piket</div>
                                    <div className="mt-1 text-xl font-bold text-purple-900">{summary.per_jenis.piket?.jumlah_jam ?? 0}</div>
                                    <div className="text-[11px] text-purple-600">jam</div>
                                </div>
                            </div>
                        </div>
                    </header>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-6 py-3 lg:px-8">
                        <div className="flex flex-wrap items-center gap-2 text-xs">
                            {(['utama', 'damping', 'piket'] as JenisTugasKode[]).map((type) => (
                                <label key={type} className="flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-gray-600 hover:bg-gray-50">
                                    <input
                                        type="checkbox"
                                        checked={filterType[type]}
                                        onChange={() => setFilterType((current) => ({ ...current, [type]: !current[type] }))}
                                        className="rounded border-gray-300 text-[#A9784F] focus:ring-[#A9784F]"
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
                                className="w-72 max-w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#A9784F] focus:ring-2 focus:ring-[#A9784F]/10"
                            />
                        </div>
                    </div>

                    <div className="flex-1 space-y-6 overflow-auto p-4 lg:p-6">
                        {filterType.utama && (
                            <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-4">
                                    <div>
                                        <h3 className="font-bold text-[#3A322C]">Matriks Guru × Kelas</h3>
                                        <p className="mt-0.5 text-xs text-gray-500">Beban mengajar utama per mata pelajaran dan kelas.</p>
                                    </div>
                                    <span className="text-xs text-gray-500">Geser tabel ke kanan untuk melihat kelas lainnya</span>
                                </div>
                                <div className="max-h-[68vh] overflow-auto">
                                    <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                                        <thead>
                                            <tr>
                                                <th className="sticky left-0 top-0 z-40 min-w-[180px] border-b border-r border-gray-200 bg-gray-100 px-4 py-3 text-left font-bold text-gray-700">Nama Guru</th>
                                                <th className="sticky top-0 z-30 min-w-[80px] border-b border-r border-gray-200 bg-gray-100 px-3 py-3 text-center font-bold text-gray-700">Kode</th>
                                                <th className="sticky top-0 z-30 min-w-[90px] border-b border-r border-gray-200 bg-gray-100 px-3 py-3 text-center font-bold text-gray-700">Total Jam</th>
                                                {kelasList.map((kelas) => {
                                                    const penuh = kelas.teralokasi_jam >= kelas.kuota_jam;
                                                    return (
                                                        <th key={kelas.id} className="sticky top-0 z-30 min-w-[150px] border-b border-r border-gray-200 bg-white px-3 py-3 text-center">
                                                            <div className="font-bold text-[#3A322C]">{kelas.nama_kelas}</div>
                                                            <div className="mt-0.5 text-[10px] text-gray-400">Jenjang {kelas.jenjang}</div>
                                                            <div className={`mt-1 text-xs font-semibold ${penuh ? 'text-green-700' : 'text-[#A9784F]'}`}>
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
                                                    <tr key={guru.id} className="group hover:bg-gray-50/70">
                                                        <td className="sticky left-0 z-20 border-b border-r border-gray-200 bg-white px-4 py-3 align-top group-hover:bg-gray-50">
                                                            <div className="font-semibold text-[#3A322C]">{guru.nama}</div>
                                                            <div className="mt-0.5 text-[10px] text-gray-400">Guru</div>
                                                        </td>
                                                        <td className="border-b border-r border-gray-200 px-3 py-3 text-center align-top text-gray-500">{guru.kode_guru}</td>
                                                        <td className="border-b border-r border-gray-200 px-3 py-3 text-center align-top">
                                                            <span className="font-bold text-[#A9784F]">{totalJamGuru(guru.id)}</span>
                                                        </td>
                                                        {kelasList.map((kelas) => {
                                                            const items = allocationsForCell(guru.id, kelas.id);
                                                            return (
                                                                <td key={kelas.id} className="min-w-[150px] border-b border-r border-gray-100 p-2 align-top">
                                                                    <div className="space-y-1.5">
                                                                        {items.map((item) => (
                                                                            <button
                                                                                key={item.id}
                                                                                type="button"
                                                                                onClick={() => openEditModal(item)}
                                                                                className="flex w-full items-center justify-between gap-2 rounded border border-blue-100 bg-blue-50 px-2 py-1.5 text-left transition hover:border-blue-300 hover:bg-blue-100"
                                                                                title="Klik untuk mengubah alokasi"
                                                                            >
                                                                                <span className="min-w-0 truncate text-xs font-medium text-blue-900">
                                                                                    {item.mata_pelajaran?.kode ?? item.mata_pelajaran?.nama ?? 'Mapel'}
                                                                                </span>
                                                                                <span className="shrink-0 text-xs font-bold text-blue-700">{item.jumlah_jam}</span>
                                                                            </button>
                                                                        ))}
                                                                        {items.length === 0 && <span className="text-xs text-gray-300">—</span>}
                                                                    </div>
                                                                </td>
                                                            );
                                                        })}
                                                    </tr>
                                                );
                                            })}
                                            {visibleTeachers.every((guru) => !mainAllocations.some((item) => item.guru_id === guru.id)) && (
                                                <tr>
                                                    <td colSpan={kelasList.length + 3} className="px-6 py-10 text-center text-sm text-gray-400">
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
                    <div className="my-8 w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl">
                        <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-6 py-4">
                            <div>
                                <h3 className="font-bold text-[#3A322C]">{editingItem ? 'Ubah Alokasi Tugas' : 'Tambah Alokasi Tugas'}</h3>
                                <p className="mt-0.5 text-xs text-gray-500">Tahun Ajaran {tahunAjaran.nama}</p>
                            </div>
                            <button type="button" onClick={closeModal} className="text-2xl leading-none text-gray-400 hover:text-gray-700">&times;</button>
                        </div>

                        <form onSubmit={submitForm} className="space-y-4 p-6">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">Guru</label>
                                <select
                                    value={form.data.guru_id}
                                    onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('guru_id', event.target.value)}
                                    disabled={Boolean(editingItem?.jadwal_terpasang)}
                                    className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100"
                                >
                                    <option value="">Pilih guru</option>
                                    {guruList.map((guru) => <option key={guru.id} value={guru.id}>{guru.nama} ({guru.kode_guru})</option>)}
                                </select>
                                {form.errors.guru_id && <p className="mt-1 text-xs text-red-600">{form.errors.guru_id}</p>}
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">Jenis Tugas</label>
                                <select
                                    value={form.data.jenis_tugas}
                                    onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData({
                                        ...form.data,
                                        jenis_tugas: event.target.value as JenisTugasKode,
                                        kelas_id: '',
                                        mapel_id: '',
                                    })}
                                    disabled={Boolean(editingItem?.jadwal_terpasang)}
                                    className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100"
                                >
                                    <option value="utama">Mengajar Utama</option>
                                    <option value="damping">Guru Damping</option>
                                    <option value="piket">Tugas Piket</option>
                                </select>
                                {form.errors.jenis_tugas && <p className="mt-1 text-xs text-red-600">{form.errors.jenis_tugas}</p>}
                            </div>

                            {form.data.jenis_tugas === 'utama' ? (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-gray-700">Kelas</label>
                                        <select
                                            value={form.data.kelas_id}
                                            onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('kelas_id', event.target.value)}
                                            disabled={Boolean(editingItem?.jadwal_terpasang)}
                                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100"
                                        >
                                            <option value="">Pilih kelas</option>
                                            {kelasList.map((kelas) => (
                                                <option key={kelas.id} value={kelas.id}>
                                                    {kelas.nama_lengkap || kelas.nama_kelas} · Kuota {kelas.kuota_jam} jam
                                                </option>
                                            ))}
                                        </select>
                                        {form.errors.kelas_id && <p className="mt-1 text-xs text-red-600">{form.errors.kelas_id}</p>}
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-gray-700">Mata Pelajaran</label>
                                        <select
                                            value={form.data.mapel_id}
                                            onChange={(event: React.ChangeEvent<HTMLSelectElement>) => form.setData('mapel_id', event.target.value)}
                                            disabled={Boolean(editingItem?.jadwal_terpasang)}
                                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100"
                                        >
                                            <option value="">Pilih mata pelajaran</option>
                                            {mapelList.map((mapel) => <option key={mapel.id} value={mapel.id}>{mapel.nama} ({mapel.kode})</option>)}
                                        </select>
                                        {form.errors.mapel_id && <p className="mt-1 text-xs text-red-600">{form.errors.mapel_id}</p>}
                                    </div>
                                </div>
                            ) : (
                                <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-xs leading-relaxed text-gray-600">
                                    Alokasi {jenisTugasLabel[form.data.jenis_tugas].toLowerCase()} tidak terikat pada kelas maupun mata pelajaran. Pasangan guru damping ditentukan saat pembagian jadwal, bukan di halaman ini.
                                </div>
                            )}

                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">Jumlah Jam per Minggu</label>
                                <input
                                    type="number"
                                    min={1}
                                    max={53}
                                    value={form.data.jumlah_jam}
                                    onChange={(event: React.ChangeEvent<HTMLInputElement>) => form.setData('jumlah_jam', Number(event.target.value))}
                                    className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F]"
                                />
                                {form.errors.jumlah_jam && <p className="mt-1 text-xs text-red-600">{form.errors.jumlah_jam}</p>}
                            </div>

                            {formErrors.allocation && <p className="rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{formErrors.allocation}</p>}

                            <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
                                <button type="button" onClick={closeModal} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Batal</button>
                                <button type="submit" disabled={form.processing} className="rounded-md bg-[#A9784F] px-5 py-2 text-sm font-semibold text-white hover:bg-[#7A5738] disabled:cursor-not-allowed disabled:opacity-60">
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
