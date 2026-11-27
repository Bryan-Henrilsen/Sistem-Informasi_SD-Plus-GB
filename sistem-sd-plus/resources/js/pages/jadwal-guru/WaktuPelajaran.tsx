import React, { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import waktuPelajaranRoute from '@/routes/jadwal-guru/waktu-pelajaran';

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
    kbm: 'border-sekolah-info/30 bg-sekolah-info/10 text-sekolah-info',
    istirahat: 'border-sekolah-tertunda/30 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    upacara: 'border-peran-ortu/30 bg-peran-ortu/10 text-peran-ortu',
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
    // Pesan berhasil dikirim server lewat Inertia::flash('toast') dan ditampilkan Toaster global; pesan gagal memakai toast.error
    const form = useForm<WaktuFormData>(emptyForm);
    const [search, setSearch] = useState('');
    const [activityFilter, setActivityFilter] = useState<'semua' | JenisKegiatan>('semua');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingSlot, setEditingSlot] = useState<WaktuPelajaranItem | null>(null);

    const errors = form.errors as Record<string, string>;
    const isUsedSlot = Boolean(editingSlot?.is_used);

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
                    toast.error(message);
                },
            });
            return;
        }

        form.post('/jadwal-guru/waktu-pelajaran', {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: (validationErrors) => {
                const message = Object.values(validationErrors)[0] ?? 'Waktu pelajaran gagal ditambahkan.';
                toast.error(message);
            },
        });
    };

    const deleteSlot = (slot: WaktuPelajaranItem) => {
        if (slot.is_used) {
            toast.error('Slot sudah digunakan dalam jadwal aktual dan tidak dapat dihapus.');
            return;
        }

        if (!window.confirm(`Hapus slot "${slot.nama}"? Hari berlaku untuk slot ini juga akan dihapus.`)) return;

        router.delete(`/jadwal-guru/waktu-pelajaran/${slot.id}`, {
            preserveScroll: true,
            onError: (validationErrors) => {
                const message = Object.values(validationErrors)[0] ?? 'Slot gagal dihapus.';
                toast.error(message);
            },
        });
    };

    const fieldError = (name: keyof WaktuFormData) => errors[name] ? (
        <p className="mt-1 text-xs text-sekolah-bahaya">{errors[name]}</p>
    ) : null;

    return (
        <>
            <Head title="Waktu Pelajaran" />

            <div className="flex text-foreground">

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="shrink-0 border-b border-border bg-card px-6 py-5 shadow-sm lg:px-8">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 className="text-2xl font-bold text-foreground">Master Waktu Pelajaran</h2>
                                <p className="mt-1 text-sm text-muted-foreground">Tahun Ajaran: {tahunAjaran.nama}</p>
                            </div>
                            <button
                                type="button"
                                onClick={openCreateModal}
                                className="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90"
                            >
                                + Tambah Waktu
                            </button>
                        </div>
                    </header>

                    <div className="flex-1 space-y-5 overflow-auto p-4 lg:p-8">
                        <div className="mx-auto max-w-7xl space-y-5">
                            <div className="flex items-start gap-3 rounded-lg border border-sekolah-info/30 bg-sekolah-info/10 p-4 text-sm text-sekolah-info">
                                <span className="mt-0.5 font-bold">i</span>
                                <p>
                                    Waktu pelajaran menjadi kerangka baris pada grid Pembagian Jadwal. Atur hari berlaku dan rentang jenjang agar slot hanya muncul pada kelas yang sesuai.
                                </p>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <div className="rounded-lg border border-border bg-card p-4 shadow-sm">
                                    <p className="text-xs text-muted-foreground">Total slot</p>
                                    <p className="mt-1 text-2xl font-bold text-foreground">{summary.total_slot}</p>
                                    <p className="text-[11px] text-muted-foreground">Slot waktu tahun ajaran aktif</p>
                                </div>
                                <div className="rounded-lg border border-sekolah-info/20 bg-sekolah-info/10 p-4">
                                    <p className="text-xs text-sekolah-info">KBM</p>
                                    <p className="mt-1 text-2xl font-bold text-sekolah-info">{summary.total_kbm}</p>
                                    <p className="text-[11px] text-sekolah-info">Slot kegiatan belajar</p>
                                </div>
                                <div className="rounded-lg border border-sekolah-tertunda/20 bg-sekolah-tertunda/10 p-4">
                                    <p className="text-xs text-sekolah-tertunda">Istirahat</p>
                                    <p className="mt-1 text-2xl font-bold text-sekolah-tertunda">{summary.total_istirahat}</p>
                                    <p className="text-[11px] text-sekolah-tertunda">Slot waktu istirahat</p>
                                </div>
                                <div className="rounded-lg border border-peran-ortu/20 bg-peran-ortu/10 p-4">
                                    <p className="text-xs text-peran-ortu">Upacara / apel</p>
                                    <p className="mt-1 text-2xl font-bold text-peran-ortu">{summary.total_upacara}</p>
                                    <p className="text-[11px] text-peran-ortu">Slot kegiatan khusus</p>
                                </div>
                            </div>

                            <section className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                                    <div>
                                        <h3 className="font-bold text-foreground">Daftar Waktu Pelajaran</h3>
                                        <p className="mt-0.5 text-xs text-muted-foreground">Hari berlaku dan rentang jenjang dikelola per slot.</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <select
                                            value={activityFilter}
                                            onChange={(event) => setActivityFilter(event.target.value as 'semua' | JenisKegiatan)}
                                            className="rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus:border-ring"
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
                                            className="w-52 max-w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring"
                                        />
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-border text-sm">
                                        <thead className="bg-muted/50">
                                            <tr>
                                                <th className="w-20 px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-muted-foreground">Urutan</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground">Jam ke- / Nama</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground">Waktu</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground">Durasi</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground">Jenis kegiatan</th>
                                                <th className="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-muted-foreground">Jenjang</th>
                                                <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground">Berlaku untuk</th>
                                                <th className="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-muted-foreground">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border bg-card">
                                            {visibleSlots.map((slot) => (
                                                <tr key={slot.id} className={`transition hover:bg-muted/50 ${slot.jenis_kegiatan === 'istirahat' ? 'bg-sekolah-tertunda/5' : ''}`}>
                                                    <td className="px-4 py-4 text-center font-medium text-muted-foreground">{slot.urutan}</td>
                                                    <td className="px-4 py-4">
                                                        <div className={`font-semibold ${slot.jenis_kegiatan === 'istirahat' ? 'text-sekolah-tertunda' : 'text-foreground'}`}>
                                                            {slot.jam_ke ? `Jam ${slot.jam_ke}` : slot.nama}
                                                        </div>
                                                        {slot.jam_ke && <div className="mt-0.5 text-xs text-muted-foreground">{slot.nama}</div>}
                                                        {slot.is_used && (
                                                            <div className="mt-1 text-[10px] font-medium text-sekolah-tertunda">
                                                                Digunakan pada {slot.jadwal_terpasang} jadwal
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 font-medium text-foreground">
                                                        {slot.jam_mulai} – {slot.jam_selesai}
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-muted-foreground">{durationLabel(slot.durasi_menit)}</td>
                                                    <td className="px-4 py-4">
                                                        <span className={`inline-flex rounded border px-2.5 py-1 text-[11px] font-semibold ${activityStyles[slot.jenis_kegiatan]}`}>
                                                            {slot.jenis_kegiatan === 'kbm' ? 'KBM' : activityLabels[slot.jenis_kegiatan]}
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-center text-muted-foreground">
                                                        {slot.jenjang_min}–{slot.jenjang_max}
                                                    </td>
                                                    <td className="px-4 py-4">
                                                        <div className="flex flex-wrap gap-1">
                                                            {dayOptions.filter((day) => slot.hari.includes(day.value)).map((day) => (
                                                                <span key={day.value} title={day.label} className="flex h-7 min-w-8 items-center justify-center rounded bg-primary px-1.5 text-[10px] font-bold text-primary-foreground">
                                                                    {day.short}
                                                                </span>
                                                            ))}
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-4 py-4 text-right">
                                                        <div className="flex items-center justify-end gap-3">
                                                            <button type="button" onClick={() => openEditModal(slot)} className="text-xs font-semibold text-foreground hover:underline">Edit</button>
                                                            <button
                                                                type="button"
                                                                onClick={() => deleteSlot(slot)}
                                                                disabled={slot.is_used}
                                                                title={slot.is_used ? 'Slot terpakai tidak dapat dihapus' : 'Hapus slot'}
                                                                className={`text-xs font-semibold ${slot.is_used ? 'cursor-not-allowed text-muted-foreground/50' : 'text-sekolah-bahaya hover:underline'}`}
                                                            >
                                                                Hapus
                                                            </button>
                                                        </div>
                                                        {slot.is_used && <p className="mt-1 text-[10px] text-sekolah-tertunda">Tidak dapat dihapus</p>}
                                                    </td>
                                                </tr>
                                            ))}
                                            {visibleSlots.length === 0 && (
                                                <tr>
                                                    <td colSpan={8} className="px-6 py-12 text-center text-sm text-muted-foreground">
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
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4" onMouseDown={(event) => {
                    if (event.target === event.currentTarget) closeModal();
                }}>
                    <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-card text-foreground shadow-2xl">
                        <div className="flex items-center justify-between border-b border-border bg-muted/50 px-6 py-4">
                            <div>
                                <h3 className="text-lg font-bold text-foreground">{editingSlot ? 'Edit Waktu Pelajaran' : 'Tambah Waktu Pelajaran'}</h3>
                                <p className="mt-0.5 text-xs text-muted-foreground">Tahun Ajaran {tahunAjaran.nama}</p>
                            </div>
                            <button type="button" onClick={closeModal} className="text-2xl leading-none text-muted-foreground hover:text-foreground" aria-label="Tutup">×</button>
                        </div>

                        <form onSubmit={submitForm} className="space-y-5 p-6">
                            {isUsedSlot && (
                                <div className="rounded-lg border border-sekolah-tertunda/30 bg-sekolah-tertunda/10 p-3 text-sm text-sekolah-tertunda">
                                    <p className="font-semibold">Slot ini sudah digunakan pada {editingSlot?.jadwal_terpasang} jadwal aktual.</p>
                                    <p className="mt-1 text-xs">Sesuai PRD, hanya waktu mulai dan waktu selesai yang dapat diubah. Slot tidak dapat dihapus, dan informasi lainnya dikunci agar jadwal yang sudah tersusun tidak berubah.</p>
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Urutan</label>
                                    <input type="number" min={1} value={form.data.urutan} disabled={isUsedSlot} onChange={(event) => form.setData('urutan', Number(event.target.value))} className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted" />
                                    {fieldError('urutan')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Jam ke-</label>
                                    <input type="number" min={1} value={form.data.jam_ke ?? ''} disabled={isUsedSlot || form.data.jenis_kegiatan !== 'kbm'} onChange={(event) => form.setData('jam_ke', event.target.value === '' ? null : Number(event.target.value))} placeholder="Kosongkan untuk istirahat/upacara" className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted" />
                                    {fieldError('jam_ke')}
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-foreground">Nama slot</label>
                                <input type="text" maxLength={255} value={form.data.nama} disabled={isUsedSlot} onChange={(event) => form.setData('nama', event.target.value)} placeholder="Contoh: Jam 1 atau Istirahat 1" className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted" />
                                {fieldError('nama')}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Waktu mulai</label>
                                    <input type="time" required value={form.data.jam_mulai} onChange={(event) => form.setData('jam_mulai', event.target.value)} className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring" />
                                    {fieldError('jam_mulai')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Waktu selesai</label>
                                    <input type="time" required value={form.data.jam_selesai} onChange={(event) => form.setData('jam_selesai', event.target.value)} className="w-full rounded-md border border-input px-3 py-2 text-sm outline-none focus:border-ring" />
                                    {fieldError('jam_selesai')}
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="sm:col-span-1">
                                    <label className="mb-1 block text-sm font-medium text-foreground">Jenis kegiatan</label>
                                    <select value={form.data.jenis_kegiatan} disabled={isUsedSlot} onChange={(event) => {
                                        const jenis = event.target.value as JenisKegiatan;
                                        form.setData((current) => ({
                                            ...current,
                                            jenis_kegiatan: jenis,
                                            jam_ke: jenis === 'kbm' ? (current.jam_ke ?? 1) : null,
                                        }));
                                    }} className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted">
                                        <option value="kbm">KBM</option>
                                        <option value="istirahat">Istirahat</option>
                                        <option value="upacara">Upacara / Apel</option>
                                    </select>
                                    {fieldError('jenis_kegiatan')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Jenjang minimum</label>
                                    <select value={form.data.jenjang_min} disabled={isUsedSlot} onChange={(event) => form.setData('jenjang_min', Number(event.target.value))} className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted">
                                        {[1, 2, 3, 4, 5, 6].map((level) => <option key={level} value={level}>Kelas {level}</option>)}
                                    </select>
                                    {fieldError('jenjang_min')}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-foreground">Jenjang maksimum</label>
                                    <select value={form.data.jenjang_max} disabled={isUsedSlot} onChange={(event) => form.setData('jenjang_max', Number(event.target.value))} className="w-full rounded-md border border-input bg-card px-3 py-2 text-sm outline-none focus:border-ring disabled:bg-muted">
                                        {[1, 2, 3, 4, 5, 6].map((level) => <option key={level} value={level}>Kelas {level}</option>)}
                                    </select>
                                    {fieldError('jenjang_max')}
                                </div>
                            </div>

                            <div>
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <label className="block text-sm font-medium text-foreground">Berlaku untuk hari</label>
                                    <span className="text-xs text-muted-foreground">Pilih minimal satu hari</span>
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
                                                className={`rounded-md border px-3 py-2 text-sm font-medium transition ${checked ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-card text-muted-foreground hover:border-ring'} disabled:cursor-not-allowed disabled:opacity-60`}
                                            >
                                                {day.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {fieldError('hari')}
                                {errors['hari.0'] && <p className="mt-1 text-xs text-sekolah-bahaya">{errors['hari.0']}</p>}
                            </div>

                            <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                                <button type="button" onClick={closeModal} className="rounded-md border border-input bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted/50">Batal</button>
                                <button type="submit" disabled={form.processing} className="rounded-md bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60">
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

// Breadcrumb di header layout aplikasi (Ringkasan v1.11 #52)
WaktuPelajaran.layout = {
    breadcrumbs: [{ title: 'Waktu Pelajaran', href: waktuPelajaranRoute.index() }],
};
