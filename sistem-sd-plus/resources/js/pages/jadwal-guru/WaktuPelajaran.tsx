import React, { useEffect, useMemo, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';

type JenisKegiatan = 'kbm' | 'istirahat' | 'upacara';

interface TahunAjaran {
    id: number;
    nama: string;
}

interface WaktuPelajaranItem {
    id: number;
    urutan: number;
    jam_ke: number | null;
    nama: string;
    jam_mulai: string;
    jam_selesai: string;
    durasi_menit: number;
    jenis_kegiatan: JenisKegiatan;
    jenjang_min: number;
    jenjang_max: number;
    hari: number[];
    jadwal_terpasang: number;
    is_used: boolean;
}

interface WaktuPelajaranProps {
    tahunAjaran: TahunAjaran;
    slots: WaktuPelajaranItem[];
    summary: {
        total_slot: number;
        total_kbm: number;
        total_istirahat: number;
        total_upacara: number;
    };
}

interface PageProps {
    flash?: { success?: string; error?: string };
    [key: string]: unknown;
}

interface WaktuFormData {
    urutan: number;
    jam_ke: number | null;
    nama: string;
    jam_mulai: string;
    jam_selesai: string;
    jenis_kegiatan: JenisKegiatan;
    jenjang_min: number;
    jenjang_max: number;
    hari: number[];
}

const dayOptions = [
    { value: 1, label: 'Senin', short: 'Sen' },
    { value: 2, label: 'Selasa', short: 'Sel' },
    { value: 3, label: 'Rabu', short: 'Rab' },
    { value: 4, label: 'Kamis', short: 'Kam' },
    { value: 5, label: 'Jumat', short: 'Jum' },
];

const activityLabels: Record<JenisKegiatan, string> = {
    kbm: 'Kegiatan Belajar Mengajar',
    istirahat: 'Istirahat',
    upacara: 'Upacara / Apel',
};

const activityStyles: Record<JenisKegiatan, string> = {
    kbm: 'border-blue-200 bg-blue-50 text-blue-800',
    istirahat: 'border-orange-200 bg-orange-50 text-orange-800',
    upacara: 'border-purple-200 bg-purple-50 text-purple-800',
};

const emptyForm: WaktuFormData = {
    urutan: 1,
    jam_ke: 1,
    nama: 'Jam 1',
    jam_mulai: '07:00',
    jam_selesai: '07:35',
    jenis_kegiatan: 'kbm',
    jenjang_min: 1,
    jenjang_max: 6,
    hari: [1, 2, 3, 4, 5],
};

export default function WaktuPelajaran({ tahunAjaran, slots, summary }: WaktuPelajaranProps) {
    const { flash } = usePage<PageProps>().props;
    const form = useForm<WaktuFormData>(emptyForm);
    const [search, setSearch] = useState('');
    const [activityFilter, setActivityFilter] = useState<'semua' | JenisKegiatan>('semua');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingSlot, setEditingSlot] = useState<WaktuPelajaranItem | null>(null);
    const [toast, setToast] = useState<{ show: boolean; message: string; type: 'success' | 'error' }>({
        show: false,
        message: '',
        type: 'success',
    });

    const errors = form.errors as Record<string, string>;
    const isUsedSlot = Boolean(editingSlot?.is_used);

    useEffect(() => {
        if (!flash?.success && !flash?.error) return;

        const isError = Boolean(flash.error);
        setToast({
            show: true,
            message: flash.error ?? flash.success ?? '',
            type: isError ? 'error' : 'success',
        });

        const timer = window.setTimeout(() => {
            setToast({ show: false, message: '', type: 'success' });
        }, isError ? 4500 : 3500);

        return () => window.clearTimeout(timer);
    }, [flash]);

    const visibleSlots = useMemo(() => {
        const term = search.trim().toLowerCase();

        return slots.filter((slot) => {
            const matchesSearch = !term ||
                slot.nama.toLowerCase().includes(term) ||
                String(slot.jam_ke ?? '').includes(term) ||
                slot.jenis_kegiatan.toLowerCase().includes(term);
            const matchesActivity = activityFilter === 'semua' || slot.jenis_kegiatan === activityFilter;

            return matchesSearch && matchesActivity;
        });
    }, [slots, search, activityFilter]);

    const durationLabel = (minutes: number) => {
        if (minutes >= 60) {
            const hours = Math.floor(minutes / 60);
            const remaining = minutes % 60;
            return remaining > 0 ? `${hours} jam ${remaining} menit` : `${hours} jam`;
        }
        return `${minutes} menit`;
    };

    const openCreateModal = () => {
        const nextUrutan = slots.reduce((max, slot) => Math.max(max, slot.urutan), 0) + 1;
        const nextJamKe = slots.reduce((max, slot) => Math.max(max, slot.jam_ke ?? 0), 0) + 1;

        setEditingSlot(null);
        form.clearErrors();
        form.setData({
            ...emptyForm,
            urutan: nextUrutan,
            jam_ke: nextJamKe,
            nama: `Jam ${nextJamKe}`,
        });
        setIsModalOpen(true);
    };

    const openEditModal = (slot: WaktuPelajaranItem) => {
        setEditingSlot(slot);
        form.clearErrors();
        form.setData({
            urutan: slot.urutan,
            jam_ke: slot.jam_ke,
            nama: slot.nama,
            jam_mulai: slot.jam_mulai,
            jam_selesai: slot.jam_selesai,
            jenis_kegiatan: slot.jenis_kegiatan,
            jenjang_min: slot.jenjang_min,
            jenjang_max: slot.jenjang_max,
            hari: [...slot.hari],
        });
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingSlot(null);
        form.reset();
        form.clearErrors();
    };

    const toggleDay = (day: number) => {
        if (isUsedSlot) return;

        const selected = form.data.hari.includes(day)
            ? form.data.hari.filter((item) => item !== day)
            : [...form.data.hari, day];

        form.setData('hari', selected.sort((a, b) => a - b));
    };

    const submitForm = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (editingSlot) {
            form.put(`/jadwal-guru/waktu-pelajaran/${editingSlot.id}`, {
                preserveScroll: true,
                onSuccess: closeModal,
                onError: (validationErrors) => {
                    const message = Object.values(validationErrors)[0] ?? 'Waktu pelajaran gagal diperbarui.';
                    setToast({ show: true, message, type: 'error' });
                },
            });
            return;
        }

        form.post('/jadwal-guru/waktu-pelajaran', {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: (validationErrors) => {
                const message = Object.values(validationErrors)[0] ?? 'Waktu pelajaran gagal ditambahkan.';
                setToast({ show: true, message, type: 'error' });
            },
        });
    };

    const deleteSlot = (slot: WaktuPelajaranItem) => {
        if (slot.is_used) {
            setToast({
                show: true,
                message: 'Slot sudah digunakan dalam jadwal aktual dan tidak dapat dihapus.',
                type: 'error',
            });
            return;
        }

        if (!window.confirm(`Hapus slot "${slot.nama}"? Hari berlaku untuk slot ini juga akan dihapus.`)) return;

        router.delete(`/jadwal-guru/waktu-pelajaran/${slot.id}`, {
            preserveScroll: true,
            onError: (validationErrors) => {
                const message = Object.values(validationErrors)[0] ?? 'Slot gagal dihapus.';
                setToast({ show: true, message, type: 'error' });
            },
        });
    };

    const fieldError = (name: keyof WaktuFormData) => errors[name] ? (
        <p className="mt-1 text-xs text-red-600">{errors[name]}</p>
    ) : null;

    return (
        <>
            <Head title="Waktu Pelajaran" />

            {toast.show && (
                <div className={`fixed left-1/2 top-5 z-[80] -translate-x-1/2 rounded-lg border px-5 py-3 text-sm font-medium shadow-lg ${
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
                        <a href="/jadwal-guru/waktu-pelajaran" className="block rounded-md border-l-4 border-[#A9784F] bg-[#A9784F]/10 px-3 py-2 text-sm font-medium text-[#7A5738]">Waktu Pelajaran</a>
                        <a href="/jadwal-guru/kisi-kisi" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Kisi-Kisi Tugas</a>
                        <a href="/jadwal-guru/pembagian" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Pembagian Jadwal</a>
                        <a href="/jadwal-guru/hasil" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Hasil Jadwal</a>
                    </nav>
                </aside>

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="shrink-0 border-b border-gray-200 bg-white px-6 py-5 shadow-sm lg:px-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold text-[#7A5738]">Master Waktu Pelajaran</h2>
                                <p className="mt-1 text-sm text-gray-500">Tahun Ajaran: {tahunAjaran.nama}</p>
                            </div>
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md bg-[#A9784F] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#7A5738]"
                            >
                                + Tambah Waktu
                            </button>
                        </div>
                    </header>

                    <div className="flex-1 space-y-5 overflow-auto p-4 lg:p-8">
                        <div className="mx-auto max-w-7xl space-y-5">
                            <div className="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                                <span className="mt-0.5 font-bold">i</span>
                                <p>
                                    Waktu pelajaran menjadi kerangka baris pada grid Pembagian Jadwal. Atur hari berlaku dan rentang jenjang agar slot hanya muncul pada kelas yang sesuai.
                                </p>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                                    <p className="text-xs text-gray-500">Total slot</p>
                                    <p className="mt-1 text-2xl font-bold text-[#3A322C]">{summary.total_slot}</p>
                                    <p className="text-[11px] text-gray-400">Slot waktu tahun ajaran aktif</p>
                                </div>
                                <div className="rounded-lg border border-blue-100 bg-blue-50/70 p-4">
                                    <p className="text-xs text-blue-700">KBM</p>
                                    <p className="mt-1 text-2xl font-bold text-blue-900">{summary.total_kbm}</p>
                                    <p className="text-[11px] text-blue-600">Slot kegiatan belajar</p>
                                </div>
                                <div className="rounded-lg border border-orange-100 bg-orange-50/70 p-4">
                                    <p className="text-xs text-orange-700">Istirahat</p>
                                    <p className="mt-1 text-2xl font-bold text-orange-900">{summary.total_istirahat}</p>
                                    <p className="text-[11px] text-orange-600">Slot waktu istirahat</p>
                                </div>
                                <div className="rounded-lg border border-purple-100 bg-purple-50/70 p-4">
                                    <p className="text-xs text-purple-700">Upacara / apel</p>
                                    <p className="mt-1 text-2xl font-bold text-purple-900">{summary.total_upacara}</p>
                                    <p className="text-[11px] text-purple-600">Slot kegiatan khusus</p>
                                </div>
                            </div>

                            <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                                    <div>
                                        <h3 className="font-bold text-[#3A322C]">Daftar Waktu Pelajaran</h3>
                                        <p className="mt-0.5 text-xs text-gray-500">Hari berlaku dan rentang jenjang dikelola per slot.</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <select
                                            value={activityFilter}
                                            onChange={(event) => setActivityFilter(event.target.value as 'semua' | JenisKegiatan)}
                                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F]"
                                        >
                                            <option value="semua">Semua kegiatan</option>
                                            <option value="kbm">KBM</option>
                                            <option value="istirahat">Istirahat</option>
                                            <option value="upacara">Upacara / Apel</option>
                                        </select>
                                        <input
                                            type="search"
                                            value={search}
                                            onChange={(event) => setSearch(event.target.value)}
                                            placeholder="Cari nama slot..."
                                            className="w-52 max-w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F]"
                                        />
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="w-20 px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-gray-600">Urutan</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Jam ke- / Nama</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Waktu</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Durasi</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Jenis kegiatan</th>
                                                <th className="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-gray-600">Jenjang</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-600">Berlaku untuk</th>
                                                <th className="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-600">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100 bg-white">
                                            {visibleSlots.map((slot) => (
                                                <tr key={slot.id} className={`transition hover:bg-gray-50 ${slot.jenis_kegiatan === 'istirahat' ? 'bg-orange-50/30' : ''}`}>
                                                    <td className="px-4 py-4 text-center font-medium text-gray-500">{slot.urutan}</td>
                                                    <td className="px-4 py-4">
                                                        <div className={`font-semibold ${slot.jenis_kegiatan === 'istirahat' ? 'text-orange-800' : 'text-[#3A322C]'}`}>
                                                            {slot.jam_ke ? `Jam ${slot.jam_ke}` : slot.nama}
                                                        </div>
                                                        {slot.jam_ke && <div className="mt-0.5 text-xs text-gray-500">{slot.nama}</div>}
                                                        {slot.is_used && (
                                                            <div className="mt-1 text-[10px] font-medium text-amber-700">
                                                                Digunakan pada {slot.jadwal_terpasang} jadwal
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 font-medium text-gray-700">
                                                        {slot.jam_mulai} – {slot.jam_selesai}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-gray-600">{durationLabel(slot.durasi_menit)}</td>
                                                    <td className="px-4 py-4">
                                                        <span className={`inline-flex rounded border px-2.5 py-1 text-[11px] font-semibold ${activityStyles[slot.jenis_kegiatan]}`}>
                                                            {slot.jenis_kegiatan === 'kbm' ? 'KBM' : activityLabels[slot.jenis_kegiatan]}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-center text-gray-600">
                                                        {slot.jenjang_min}–{slot.jenjang_max}
                                                    </td>
                                                    <td className="px-4 py-4">
                                                        <div className="flex flex-wrap gap-1">
                                                            {dayOptions.filter((day) => slot.hari.includes(day.value)).map((day) => (
                                                                <span key={day.value} title={day.label} className="flex h-7 min-w-8 items-center justify-center rounded bg-[#A9784F] px-1.5 text-[10px] font-bold text-white">
                                                                    {day.short}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-right">
                                                        <div className="flex items-center justify-end gap-3">
                                                            <button type="button" onClick={() => openEditModal(slot)} className="text-xs font-semibold text-[#7A5738] hover:underline">Edit</button>
                                                            <button
                                                                type="button"
                                                                onClick={() => deleteSlot(slot)}
                                                                disabled={slot.is_used}
                                                                title={slot.is_used ? 'Slot terpakai tidak dapat dihapus' : 'Hapus slot'}
                                                                className={`text-xs font-semibold ${slot.is_used ? 'cursor-not-allowed text-gray-300' : 'text-red-600 hover:underline'}`}
                                                            >
                                                                Hapus
                                                            </button>
                                                        </div>
                                                        {slot.is_used && <p className="mt-1 text-[10px] text-amber-700">Tidak dapat dihapus</p>}
                                                    </td>
                                                </tr>
                                            ))}
                                            {visibleSlots.length === 0 && (
                                                <tr>
                                                    <td colSpan={8} className="px-6 py-12 text-center text-sm text-gray-400">
                                                        Tidak ada slot yang cocok dengan pencarian atau filter.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </div>
                </main>
            </div>

            {isModalOpen && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-gray-900/50 p-4" onMouseDown={(event) => {
                    if (event.target === event.currentTarget) closeModal();
                }}>
                    <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white text-gray-900 shadow-2xl">
                        <div className="flex items-center justify-between border-b border-gray-200 bg-gray-50 px-6 py-4">
                            <div>
                                <h3 className="text-lg font-bold text-[#3A322C]">{editingSlot ? 'Edit Waktu Pelajaran' : 'Tambah Waktu Pelajaran'}</h3>
                                <p className="mt-0.5 text-xs text-gray-500">Tahun Ajaran {tahunAjaran.nama}</p>
                            </div>
                            <button type="button" onClick={closeModal} className="text-2xl leading-none text-gray-400 hover:text-gray-700" aria-label="Tutup">×</button>
                        </div>

                        <form onSubmit={submitForm} className="space-y-5 p-6">
                            {isUsedSlot && (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                    <p className="font-semibold">Slot ini sudah digunakan pada {editingSlot?.jadwal_terpasang} jadwal aktual.</p>
                                    <p className="mt-1 text-xs">Sesuai PRD, hanya waktu mulai dan waktu selesai yang dapat diubah. Slot tidak dapat dihapus, dan informasi lainnya dikunci agar jadwal yang sudah tersusun tidak berubah.</p>
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Urutan</label>
                                    <input type="number" min={1} value={form.data.urutan} disabled={isUsedSlot} onChange={(event) => form.setData('urutan', Number(event.target.value))} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100" />
                                    {fieldError('urutan')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Jam ke-</label>
                                    <input type="number" min={1} value={form.data.jam_ke ?? ''} disabled={isUsedSlot || form.data.jenis_kegiatan !== 'kbm'} onChange={(event) => form.setData('jam_ke', event.target.value === '' ? null : Number(event.target.value))} placeholder="Kosongkan untuk istirahat/upacara" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100" />
                                    {fieldError('jam_ke')}
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-gray-700">Nama slot</label>
                                <input type="text" maxLength={255} value={form.data.nama} disabled={isUsedSlot} onChange={(event) => form.setData('nama', event.target.value)} placeholder="Contoh: Jam 1 atau Istirahat 1" className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100" />
                                {fieldError('nama')}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Waktu mulai</label>
                                    <input type="time" required value={form.data.jam_mulai} onChange={(event) => form.setData('jam_mulai', event.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F]" />
                                    {fieldError('jam_mulai')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Waktu selesai</label>
                                    <input type="time" required value={form.data.jam_selesai} onChange={(event) => form.setData('jam_selesai', event.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm outline-none focus:border-[#A9784F]" />
                                    {fieldError('jam_selesai')}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="sm:col-span-1">
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Jenis kegiatan</label>
                                    <select value={form.data.jenis_kegiatan} disabled={isUsedSlot} onChange={(event) => {
                                        const jenis = event.target.value as JenisKegiatan;
                                        form.setData((current) => ({
                                            ...current,
                                            jenis_kegiatan: jenis,
                                            jam_ke: jenis === 'kbm' ? (current.jam_ke ?? 1) : null,
                                        }));
                                    }} className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100">
                                        <option value="kbm">KBM</option>
                                        <option value="istirahat">Istirahat</option>
                                        <option value="upacara">Upacara / Apel</option>
                                    </select>
                                    {fieldError('jenis_kegiatan')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Jenjang minimum</label>
                                    <select value={form.data.jenjang_min} disabled={isUsedSlot} onChange={(event) => form.setData('jenjang_min', Number(event.target.value))} className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100">
                                        {[1, 2, 3, 4, 5, 6].map((level) => <option key={level} value={level}>Kelas {level}</option>)}
                                    </select>
                                    {fieldError('jenjang_min')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-gray-700">Jenjang maksimum</label>
                                    <select value={form.data.jenjang_max} disabled={isUsedSlot} onChange={(event) => form.setData('jenjang_max', Number(event.target.value))} className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F] disabled:bg-gray-100">
                                        {[1, 2, 3, 4, 5, 6].map((level) => <option key={level} value={level}>Kelas {level}</option>)}
                                    </select>
                                    {fieldError('jenjang_max')}
                                </div>
                            </div>

                            <div>
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <label className="block text-sm font-medium text-gray-700">Berlaku untuk hari</label>
                                    <span className="text-xs text-gray-400">Pilih minimal satu hari</span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {dayOptions.map((day) => {
                                        const checked = form.data.hari.includes(day.value);
                                        return (
                                            <button
                                                key={day.value}
                                                type="button"
                                                disabled={isUsedSlot}
                                                onClick={() => toggleDay(day.value)}
                                                className={`rounded-md border px-3 py-2 text-sm font-medium transition ${checked ? 'border-[#A9784F] bg-[#A9784F] text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-[#A9784F]'} disabled:cursor-not-allowed disabled:opacity-60`}
                                            >
                                                {day.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {fieldError('hari')}
                                {errors['hari.0'] && <p className="mt-1 text-xs text-red-600">{errors['hari.0']}</p>}
                            </div>

                            <div className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
                                <button type="button" onClick={closeModal} className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Batal</button>
                                <button type="submit" disabled={form.processing} className="rounded-md bg-[#A9784F] px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#7A5738] disabled:cursor-not-allowed disabled:opacity-60">
                                    {form.processing ? 'Menyimpan...' : editingSlot ? 'Simpan Perubahan' : 'Simpan Waktu'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );
}
