import React, { useMemo, useState } from 'react';
import { Head } from '@inertiajs/react';

interface TahunAjaran {
    id: number;
    nama: string;
}

interface KelasItem {
    id: number;
    nama_kelas: string;
    nama_lengkap?: string | null;
    jenjang: number;
}

interface GuruItem {
    id: number;
    kode_guru: string;
    nama: string;
}

interface WaktuPelajaranItem {
    id: number;
    urutan: number;
    jam_ke: number | null;
    nama: string;
    jam_mulai: string;
    jam_selesai: string;
    jenis_kegiatan: string;
    jenjang_min: number;
    jenjang_max: number;
    hari: number[];
}

interface JadwalItem {
    id: number;
    hari: number;
    waktu_pelajaran_id: number;
    parent_jadwal_id: number | null;
    guru: {
        id: number;
        kode_guru?: string | null;
        nama: string;
    };
    kelas: {
        id: number;
        nama_kelas: string;
        nama_lengkap?: string | null;
        jenjang: number;
    } | null;
    mata_pelajaran: {
        id: number;
        kode?: string | null;
        nama: string;
    } | null;
    jenis_tugas: {
        kode: string | null;
        nama: string | null;
    };
}

interface HasilJadwalProps {
    tahunAjaran: TahunAjaran;
    daftarKelas: KelasItem[];
    daftarGuru: GuruItem[];
    waktuPelajaran: WaktuPelajaranItem[];
    jadwal: JadwalItem[];
}

const days = [
    { id: 1, name: 'Senin' },
    { id: 2, name: 'Selasa' },
    { id: 3, name: 'Rabu' },
    { id: 4, name: 'Kamis' },
    { id: 5, name: 'Jumat' },
];

const formatTime = (time: string) => (time ? time.substring(0, 5) : '-');
const formatRange = (slot: WaktuPelajaranItem) =>
    `${formatTime(slot.jam_mulai)} – ${formatTime(slot.jam_selesai)}`;

const slotAppliesToClass = (slot: WaktuPelajaranItem, kelas: KelasItem, day: number) =>
    slot.hari.includes(day) &&
    kelas.jenjang >= slot.jenjang_min &&
    kelas.jenjang <= slot.jenjang_max;

const classLabel = (kelas: KelasItem | null) => {
    if (!kelas) return 'Piket';
    return kelas.nama_lengkap || `Kelas ${kelas.nama_kelas}`;
};

export default function HasilJadwal({
    tahunAjaran,
    daftarKelas,
    daftarGuru,
    waktuPelajaran,
    jadwal,
}: HasilJadwalProps) {
    const [mode, setMode] = useState<'kelas' | 'guru'>('kelas');
    const [kelasId, setKelasId] = useState<number | null>(daftarKelas[0]?.id ?? null);
    const [guruId, setGuruId] = useState<number | null>(daftarGuru[0]?.id ?? null);
    const [kelasView, setKelasView] = useState<'tabel' | 'kalender'>('tabel');
    const [guruView, setGuruView] = useState<'tabel' | 'kalender'>('tabel');

    const selectedKelas = daftarKelas.find((item) => item.id === kelasId) ?? null;
    const selectedGuru = daftarGuru.find((item) => item.id === guruId) ?? null;

    const sortedSlots = useMemo(
        () => [...waktuPelajaran].sort((a, b) => a.urutan - b.urutan),
        [waktuPelajaran],
    );

    const classRows = useMemo(() => {
        if (!selectedKelas) return [];

        return days.flatMap((day) =>
            sortedSlots
                .filter((slot) => slotAppliesToClass(slot, selectedKelas, day.id))
                .map((slot) => {
                    const schedules = jadwal.filter(
                        (item) =>
                            item.kelas?.id === selectedKelas.id &&
                            item.hari === day.id &&
                            item.waktu_pelajaran_id === slot.id,
                    );

                    return {
                        day,
                        slot,
                        main: schedules.filter((item) => item.jenis_tugas.kode === 'utama'),
                        damping: schedules.filter((item) => item.jenis_tugas.kode === 'damping'),
                    };
                }),
        );
    }, [selectedKelas, sortedSlots, jadwal]);

    const teacherRows = useMemo(() => {
        if (!selectedGuru) return [];

        return days.flatMap((day) =>
            sortedSlots
                .filter((slot) => slot.hari.includes(day.id))
                .map((slot) => ({
                    day,
                    slot,
                    schedule: jadwal.find(
                        (item) =>
                            item.guru.id === selectedGuru.id &&
                            item.hari === day.id &&
                            item.waktu_pelajaran_id === slot.id,
                    ) ?? null,
                })),
        );
    }, [selectedGuru, sortedSlots, jadwal]);

    const classDailySummary = days.map((day) => ({
        ...day,
        total: classRows.filter(
            (row) => row.day.id === day.id && row.slot.jenis_kegiatan === 'kbm' && row.main.length > 0,
        ).length,
    }));

    const teacherDailySummary = days.map((day) => ({
        ...day,
        total: teacherRows.filter((row) => row.day.id === day.id && row.schedule !== null).length,
    }));

    const classTotal = classDailySummary.reduce((total, day) => total + day.total, 0);
    const teacherTotal = teacherDailySummary.reduce((total, day) => total + day.total, 0);

    const printSchedule = () => window.print();

    const renderMainAndDamping = (mainItems: JadwalItem[], dampingItems: JadwalItem[]) => {
        if (mainItems.length === 0 && dampingItems.length === 0) {
            return <span className="text-gray-400 italic">Belum dijadwalkan</span>;
        }

        return (
            <div className="space-y-1">
                {mainItems.map((item) => (
                    <div key={item.id}>
                        <div className="font-semibold text-[#3A322C]">{item.guru.nama}</div>
                        <div className="text-xs text-gray-500">
                            {item.mata_pelajaran?.nama ?? item.jenis_tugas.nama ?? 'Mengajar utama'}
                        </div>
                    </div>
                ))}
                {dampingItems.map((item) => (
                    <div key={item.id} className="rounded bg-purple-50 px-2 py-1 text-xs text-purple-800">
                        Damping: {item.guru.nama}
                    </div>
                ))}
            </div>
        );
    };

    const renderTeacherSchedule = (item: JadwalItem | null) => {
        if (!item) return <span className="text-gray-400 italic">Kosong</span>;

        const taskLabel = item.jenis_tugas.kode === 'piket'
            ? 'Piket'
            : item.jenis_tugas.kode === 'damping'
                ? `Damping${item.mata_pelajaran?.nama ? ` · ${item.mata_pelajaran.nama}` : ''}`
                : item.mata_pelajaran?.nama ?? item.jenis_tugas.nama ?? 'Mengajar';

        return (
            <div>
                <div className="font-semibold text-[#3A322C]">{classLabel(item.kelas)}</div>
                <div className="text-xs text-gray-500">{taskLabel}</div>
            </div>
        );
    };

    return (
        <div className="flex h-screen overflow-hidden bg-[#FAF7F2] font-sans text-[#3A322C] antialiased">
            <Head title="Hasil Jadwal" />
            <style>{`
                @media print {
                    body { background: #fff !important; }
                    aside, .no-print { display: none !important; }
                    .hasil-jadwal-main { height: auto !important; overflow: visible !important; }
                    .hasil-jadwal-content { overflow: visible !important; padding: 0 !important; }
                    .hasil-jadwal-card { break-inside: avoid; box-shadow: none !important; }
                }
            `}</style>

            <aside className="no-print z-20 flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-200 p-6">
                    <h1 className="text-xl font-bold leading-tight text-[#7A5738]">SD Plus<br />Gembala Baik</h1>
                    <p className="mt-1 text-xs text-gray-500">Manajemen Akademik</p>
                </div>
                <nav className="flex-1 space-y-1 overflow-y-auto p-4">
                    <div className="mb-2 mt-6 text-xs font-bold uppercase tracking-wider text-gray-400">Modul Jadwal Guru</div>
                    <a href="/jadwal-guru/waktu-pelajaran" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Waktu Pelajaran</a>
                    <a href="/jadwal-guru/kisi-kisi" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Kisi-Kisi Tugas</a>
                    <a href="/jadwal-guru/pembagian" className="block rounded-md border-l-4 border-transparent px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Pembagian Jadwal</a>
                    <a href="/jadwal-guru/hasil" className="block rounded-md border-l-4 border-[#A9784F] bg-[#A9784F]/10 px-3 py-2 text-sm font-medium text-[#7A5738]">Hasil Jadwal</a>
                </nav>
            </aside>

            <main className="hasil-jadwal-main flex min-w-0 flex-1 flex-col overflow-hidden">
                <header className="no-print shrink-0 border-b border-gray-200 bg-white px-6 py-5 shadow-sm lg:px-8">
                    <h2 className="text-2xl font-bold text-[#7A5738]">Hasil Jadwal</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Tahun Ajaran {tahunAjaran.nama}. Lihat jadwal pelajaran per kelas atau per guru.
                    </p>
                </header>

                <div className="hasil-jadwal-content flex-1 space-y-6 overflow-auto p-4 lg:p-6">
                    <div className="mx-auto max-w-7xl space-y-6">
                        <div className="no-print mx-auto flex w-full max-w-2xl rounded-lg bg-gray-200/70 p-1 shadow-inner">
                            <button
                                type="button"
                                onClick={() => setMode('kelas')}
                                className={`flex-1 rounded-md py-2 text-sm font-bold transition-colors ${mode === 'kelas' ? 'bg-[#A9784F] text-white shadow' : 'text-gray-600 hover:text-[#3A322C]'}`}
                            >
                                Per Kelas
                            </button>
                            <button
                                type="button"
                                onClick={() => setMode('guru')}
                                className={`flex-1 rounded-md py-2 text-sm font-bold transition-colors ${mode === 'guru' ? 'bg-[#A9784F] text-white shadow' : 'text-gray-600 hover:text-[#3A322C]'}`}
                            >
                                Per Guru
                            </button>
                        </div>

                        {mode === 'kelas' ? (
                            <section className="hasil-jadwal-card space-y-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:p-5">
                                <div className="flex flex-col items-start justify-between gap-4 border-b border-gray-100 pb-4 md:flex-row md:items-center">
                                    <div>
                                        <h3 className="text-xl font-bold text-[#3A322C]">
                                            {selectedKelas ? `Kelas ${selectedKelas.nama_kelas}` : 'Jadwal Per Kelas'}
                                        </h3>
                                        <p className="mt-1 text-xs text-gray-500">
                                            {selectedKelas ? `Jenjang ${selectedKelas.jenjang} · Tahun Ajaran ${tahunAjaran.nama}` : 'Belum ada kelas pada tahun ajaran aktif.'}
                                        </p>
                                    </div>
                                    <div className="no-print flex flex-wrap items-center gap-3">
                                        <div className="flex rounded border border-gray-200 bg-gray-100 p-0.5">
                                            <button type="button" onClick={() => setKelasView('tabel')} className={`rounded px-3 py-1.5 text-xs font-bold ${kelasView === 'tabel' ? 'border border-gray-200 bg-white text-[#A9784F] shadow-sm' : 'text-gray-500'}`}>Tabel</button>
                                            <button type="button" onClick={() => setKelasView('kalender')} className={`rounded px-3 py-1.5 text-xs font-bold ${kelasView === 'kalender' ? 'border border-gray-200 bg-white text-[#A9784F] shadow-sm' : 'text-gray-500'}`}>Kalender</button>
                                        </div>
                                        <select
                                            aria-label="Pilih kelas"
                                            value={kelasId ?? ''}
                                            onChange={(event) => setKelasId(event.target.value ? Number(event.target.value) : null)}
                                            className="min-w-44 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F]"
                                        >
                                            {daftarKelas.length === 0 && <option value="">Tidak ada kelas</option>}
                                            {daftarKelas.map((kelas) => <option key={kelas.id} value={kelas.id}>{`Kelas ${kelas.nama_kelas}`}</option>)}
                                        </select>
                                        <button type="button" onClick={printSchedule} className="rounded-md bg-[#A9784F] px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-[#7A5738]">Cetak / Simpan PDF</button>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                    {classDailySummary.map((day) => (
                                        <div key={day.id} className="rounded-lg border border-gray-200 bg-gray-50/60 p-3 text-center">
                                            <div className="mb-1 text-xs font-medium text-gray-500">{day.name}</div>
                                            <div className="text-xl font-bold text-[#A9784F]">{selectedKelas ? day.total : '–'}</div>
                                            <div className="text-[10px] text-gray-400">jam utama</div>
                                        </div>
                                    ))}
                                    <div className="rounded-lg border border-[#A9784F] bg-[#A9784F]/5 p-3 text-center shadow-sm">
                                        <div className="mb-1 text-xs font-bold uppercase tracking-wider text-[#7A5738]">Total</div>
                                        <div className="text-xl font-bold text-[#7A5738]">{selectedKelas ? classTotal : '–'} <span className="text-xs font-medium">jam</span></div>
                                        <div className="text-[10px] text-gray-400">terjadwal</div>
                                    </div>
                                </div>

                                {!selectedKelas ? (
                                    <div className="rounded-lg border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">Belum ada kelas yang dapat ditampilkan.</div>
                                ) : kelasView === 'tabel' ? (
                                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                                                <tr>
                                                    <th className="px-4 py-3 text-left">Hari</th>
                                                    <th className="px-4 py-3 text-center">Jam ke</th>
                                                    <th className="px-4 py-3 text-left">Waktu</th>
                                                    <th className="px-4 py-3 text-left">Guru Utama</th>
                                                    <th className="px-4 py-3 text-left">Mata Pelajaran</th>
                                                    <th className="px-4 py-3 text-left">Guru Damping</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100 bg-white">
                                                {classRows.map(({ day, slot, main, damping }) => (
                                                    <tr key={`${day.id}-${slot.id}`} className={slot.jenis_kegiatan === 'istirahat' ? 'bg-orange-50/60' : 'hover:bg-gray-50'}>
                                                        <td className="whitespace-nowrap px-4 py-3 font-semibold">{day.name}</td>
                                                        <td className="px-4 py-3 text-center">{slot.jam_ke ?? '–'}</td>
                                                        <td className="whitespace-nowrap px-4 py-3 text-gray-600">{formatRange(slot)}</td>
                                                        {slot.jenis_kegiatan !== 'kbm' ? (
                                                            <td colSpan={3} className="px-4 py-3 text-center font-bold uppercase tracking-wide text-orange-800">{slot.nama}</td>
                                                        ) : (
                                                            <>
                                                                <td className="px-4 py-3">{main.length ? main.map((item) => <div key={item.id}>{item.guru.nama}</div>) : <span className="text-gray-400 italic">Belum dijadwalkan</span>}</td>
                                                                <td className="px-4 py-3">{main.length ? main.map((item) => <div key={item.id}>{item.mata_pelajaran?.nama ?? '–'}</div>) : '–'}</td>
                                                                <td className="px-4 py-3">{damping.length ? damping.map((item) => <div key={item.id}>{item.guru.nama}</div>) : <span className="text-gray-400">–</span>}</td>
                                                            </>
                                                        )}
                                                    </tr>
                                                ))}
                                                {classRows.length === 0 && <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">Tidak ada slot yang berlaku untuk kelas ini.</td></tr>}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div className="min-w-[900px]">
                                            <div className="grid grid-cols-6 gap-2">
                                                <div className="flex items-end justify-center pb-2 text-xs font-bold uppercase text-gray-400">Jam & Waktu</div>
                                                {days.map((day) => <div key={day.id} className="rounded-lg border border-gray-200 bg-white py-2 text-center text-sm font-bold">{day.name}</div>)}
                                                {sortedSlots.map((slot) => (
                                                    <React.Fragment key={slot.id}>
                                                        <div className={`flex flex-col items-center justify-center rounded-lg border p-2 text-center ${slot.jenis_kegiatan === 'istirahat' ? 'border-orange-200 bg-orange-50' : 'border-gray-200 bg-white'}`}>
                                                            <span className="font-bold">{slot.jam_ke ?? slot.nama}</span>
                                                            <span className="mt-1 text-[10px] text-gray-500">{formatTime(slot.jam_mulai)}<br />{formatTime(slot.jam_selesai)}</span>
                                                        </div>
                                                        {days.map((day) => {
                                                            const applies = slotAppliesToClass(slot, selectedKelas, day.id);
                                                            const entries = jadwal.filter((item) => item.kelas?.id === selectedKelas.id && item.hari === day.id && item.waktu_pelajaran_id === slot.id);
                                                            const main = entries.filter((item) => item.jenis_tugas.kode === 'utama');
                                                            const damping = entries.filter((item) => item.jenis_tugas.kode === 'damping');

                                                            return (
                                                                <div key={`${slot.id}-${day.id}`} className={`min-h-20 rounded-lg border p-2 ${!applies ? 'border-gray-100 bg-gray-100/60' : slot.jenis_kegiatan === 'istirahat' ? 'border-orange-100 bg-orange-50/70' : 'border-gray-200 bg-white'}`}>
                                                                    {!applies ? <span className="text-xs text-gray-300">–</span> : slot.jenis_kegiatan !== 'kbm' ? <div className="pt-3 text-center text-[10px] font-bold uppercase text-orange-800">{slot.nama}</div> : renderMainAndDamping(main, damping)}
                                                                </div>
                                                            );
                                                        })}
                                                    </React.Fragment>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </section>
                        ) : (
                            <section className="hasil-jadwal-card space-y-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:p-5">
                                <div className="flex flex-col items-start justify-between gap-4 border-b border-gray-100 pb-4 md:flex-row md:items-center">
                                    <div>
                                        <h3 className="text-xl font-bold text-[#3A322C]">{selectedGuru ? `${selectedGuru.nama} (${selectedGuru.kode_guru})` : 'Jadwal Per Guru'}</h3>
                                        <p className="mt-1 text-xs text-gray-500">Jadwal mengajar utama, damping, dan piket · Tahun Ajaran {tahunAjaran.nama}</p>
                                    </div>
                                    <div className="no-print flex flex-wrap items-center gap-3">
                                        <div className="flex rounded border border-gray-200 bg-gray-100 p-0.5">
                                            <button type="button" onClick={() => setGuruView('tabel')} className={`rounded px-3 py-1.5 text-xs font-bold ${guruView === 'tabel' ? 'border border-gray-200 bg-white text-[#A9784F] shadow-sm' : 'text-gray-500'}`}>Tabel</button>
                                            <button type="button" onClick={() => setGuruView('kalender')} className={`rounded px-3 py-1.5 text-xs font-bold ${guruView === 'kalender' ? 'border border-gray-200 bg-white text-[#A9784F] shadow-sm' : 'text-gray-500'}`}>Kalender</button>
                                        </div>
                                        <select
                                            aria-label="Pilih guru"
                                            value={guruId ?? ''}
                                            onChange={(event) => setGuruId(event.target.value ? Number(event.target.value) : null)}
                                            className="min-w-48 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm outline-none focus:border-[#A9784F]"
                                        >
                                            {daftarGuru.length === 0 && <option value="">Tidak ada guru</option>}
                                            {daftarGuru.map((guru) => <option key={guru.id} value={guru.id}>{guru.nama} ({guru.kode_guru})</option>)}
                                        </select>
                                        <button type="button" onClick={printSchedule} className="rounded-md bg-[#A9784F] px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-[#7A5738]">Cetak / Simpan PDF</button>
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                    {teacherDailySummary.map((day) => (
                                        <div key={day.id} className="rounded-lg border border-gray-200 bg-gray-50/60 p-3 text-center">
                                            <div className="mb-1 text-xs font-medium text-gray-500">{day.name}</div>
                                            <div className="text-xl font-bold text-[#A9784F]">{selectedGuru ? day.total : '–'}</div>
                                            <div className="text-[10px] text-gray-400">jam terisi</div>
                                        </div>
                                    ))}
                                    <div className="rounded-lg border border-[#A9784F] bg-[#A9784F]/5 p-3 text-center shadow-sm">
                                        <div className="mb-1 text-xs font-bold uppercase tracking-wider text-[#7A5738]">Total</div>
                                        <div className="text-xl font-bold text-[#7A5738]">{selectedGuru ? teacherTotal : '–'} <span className="text-xs font-medium">jam</span></div>
                                        <div className="text-[10px] text-gray-400">terjadwal</div>
                                    </div>
                                </div>

                                {!selectedGuru ? (
                                    <div className="rounded-lg border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">Belum ada guru aktif yang dapat ditampilkan.</div>
                                ) : guruView === 'tabel' ? (
                                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                                                <tr>
                                                    <th className="px-4 py-3 text-left">Hari</th>
                                                    <th className="px-4 py-3 text-center">Jam ke</th>
                                                    <th className="px-4 py-3 text-left">Waktu</th>
                                                    <th className="px-4 py-3 text-left">Kelas / Tugas</th>
                                                    <th className="px-4 py-3 text-left">Keterangan / Mapel</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100 bg-white">
                                                {teacherRows.map(({ day, slot, schedule }) => (
                                                    <tr key={`${day.id}-${slot.id}`} className={slot.jenis_kegiatan === 'istirahat' ? 'bg-orange-50/60' : 'hover:bg-gray-50'}>
                                                        <td className="whitespace-nowrap px-4 py-3 font-semibold">{day.name}</td>
                                                        <td className="px-4 py-3 text-center">{slot.jam_ke ?? '–'}</td>
                                                        <td className="whitespace-nowrap px-4 py-3 text-gray-600">{formatRange(slot)}</td>
                                                        {slot.jenis_kegiatan !== 'kbm' ? (
                                                            <td colSpan={2} className="px-4 py-3 text-center">
                                                                <div className="font-bold uppercase tracking-wide text-orange-800">{slot.nama}</div>
                                                                {schedule && <div className="mt-1 text-left text-xs text-[#3A322C]">{classLabel(schedule.kelas)} · {schedule.jenis_tugas.kode === 'piket' ? 'Piket' : schedule.mata_pelajaran?.nama ?? schedule.jenis_tugas.nama ?? 'Tugas'}</div>}
                                                            </td>
                                                        ) : (
                                                            <>
                                                                <td className="px-4 py-3">{schedule ? classLabel(schedule.kelas) : <span className="text-gray-400 italic">Kosong</span>}</td>
                                                                <td className="px-4 py-3">{schedule ? (schedule.jenis_tugas.kode === 'piket' ? 'Piket' : schedule.mata_pelajaran?.nama ?? schedule.jenis_tugas.nama ?? '–') : <span className="text-gray-400 italic">–</span>}</td>
                                                            </>
                                                        )}
                                                    </tr>
                                                ))}
                                                {teacherRows.length === 0 && <tr><td colSpan={5} className="px-4 py-8 text-center text-gray-500">Tidak ada slot yang berlaku pada jadwal guru ini.</td></tr>}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div className="min-w-[900px]">
                                            <div className="grid grid-cols-6 gap-2">
                                                <div className="flex items-end justify-center pb-2 text-xs font-bold uppercase text-gray-400">Jam & Waktu</div>
                                                {days.map((day) => <div key={day.id} className="rounded-lg border border-gray-200 bg-white py-2 text-center text-sm font-bold">{day.name}</div>)}
                                                {sortedSlots.map((slot) => (
                                                    <React.Fragment key={slot.id}>
                                                        <div className={`flex flex-col items-center justify-center rounded-lg border p-2 text-center ${slot.jenis_kegiatan === 'istirahat' ? 'border-orange-200 bg-orange-50' : 'border-gray-200 bg-white'}`}>
                                                            <span className="font-bold">{slot.jam_ke ?? slot.nama}</span>
                                                            <span className="mt-1 text-[10px] text-gray-500">{formatTime(slot.jam_mulai)}<br />{formatTime(slot.jam_selesai)}</span>
                                                        </div>
                                                        {days.map((day) => {
                                                            const applies = slot.hari.includes(day.id);
                                                            const schedule = jadwal.find((item) => item.guru.id === selectedGuru.id && item.hari === day.id && item.waktu_pelajaran_id === slot.id) ?? null;

                                                            return (
                                                                <div key={`${slot.id}-${day.id}`} className={`min-h-20 rounded-lg border p-2 ${!applies ? 'border-gray-100 bg-gray-100/60' : slot.jenis_kegiatan === 'istirahat' ? 'border-orange-100 bg-orange-50/70' : 'border-gray-200 bg-white'}`}>
                                                                    {!applies ? <span className="text-xs text-gray-300">–</span> : slot.jenis_kegiatan !== 'kbm' ? <div className="space-y-1 pt-2 text-center"><div className="text-[10px] font-bold uppercase text-orange-800">{slot.nama}</div>{schedule && <div className="text-left">{renderTeacherSchedule(schedule)}</div>}</div> : renderTeacherSchedule(schedule)}
                                                                </div>
                                                            );
                                                        })}
                                                    </React.Fragment>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        <p className="no-print text-xs text-gray-400">
                            Data ditampilkan berdasarkan jadwal aktual yang sudah tersimpan. Slot yang tidak diisi pada tampilan guru ditandai sebagai Kosong.
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
}
