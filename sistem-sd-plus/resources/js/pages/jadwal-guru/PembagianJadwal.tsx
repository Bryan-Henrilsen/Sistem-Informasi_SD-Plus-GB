import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// --- 1. DEFINISI STRUKTUR DATA (TYPESCRIPT INTERFACES) ---
interface WaktuPelajaran {
id: number;
jam_ke: number | null;
nama: string;
jam_mulai: string;
jam_selesai: string;
jenis_kegiatan: 'kbm' | 'istirahat' | 'upacara' | string;
jenjang_min: number;
jenjang_max: number;
hari?: { hari: number }[];
}

interface Kelas {
id: number;
nama_kelas: string;
jenjang: number;
}

interface Guru {
id: number;
kode_guru: string;
user?: { name: string };
name?: string;
}

interface JenisTugas {
id: number;
kode: 'utama' | 'damping' | 'piket' | string;
nama: string;
}

interface KisiKisiTugas {
id: number;
guru?: Guru;
kelas?: Kelas;
mata_pelajaran?: { nama: string };
jenis_tugas: JenisTugas;
sisa_jam_tersedia: number;
keterangan?: string;
}

interface JadwalMengajar {
id: number;
hari: number;
waktu_pelajaran_id: number;
kelas_id: number | null;
guru?: Guru;
kisi_kisi_tugas?: KisiKisiTugas;
jenis_tugas: JenisTugas;
}

interface PembagianJadwalProps {
waktuPelajaran: WaktuPelajaran[];
daftarKelas: Kelas[];
kisiKisiTugas: KisiKisiTugas[];
jadwalTerplot: JadwalMengajar[];
}

interface CustomPageProps {
flash?: { success?: string; error?: string };
errors?: Record<string, string>;
[key: string]: unknown;
}

export default function PembagianJadwal({ waktuPelajaran, daftarKelas, kisiKisiTugas, jadwalTerplot }: PembagianJadwalProps) {
const { errors, flash } = usePage().props;

// --- STATES ---
const [hariAktif, setHariAktif] = useState<number>(1);
const [draggedItem, setDraggedItem] = useState<KisiKisiTugas | null>(null);
const [searchTerm, setSearchTerm] = useState<string>('');
const [filterType, setFilterType] = useState<Record<string, boolean>>({ utama: true, damping: true, piket: true });

const [toast, setToast] = useState<{ show: boolean; message: string; type: 'success' | 'error' }>({ show: false, message: '', type: 'success' });
const [deleteModal, setDeleteModal] = useState<{ isOpen: boolean; jadwalId: number | null }>({ isOpen: false, jadwalId: null });

const daftarHari = [
    { id: 1, name: 'Senin' },
    { id: 2, name: 'Selasa' },
    { id: 3, name: 'Rabu' },
    { id: 4, name: 'Kamis' },
    { id: 5, name: 'Jumat' }
];

useEffect(() => {
    if (flash?.success) {
        showToast(flash.success, 'success');
    }
    if (errors?.message) {
        showToast(errors.message, 'error');
    }
}, [flash, errors]);

const showToast = (message: string, type: 'success' | 'error') => {
    setToast({ show: true, message, type });
    setTimeout(() => setToast({ show: false, message: '', type: 'success' }), 4000);
};

const confirmDelete = (jadwalId: number) => {
    setDeleteModal({ isOpen: true, jadwalId });
};

const executeDelete = () => {
    if (deleteModal.jadwalId) {
        router.delete(`/jadwal-guru/pembagian/${deleteModal.jadwalId}`, {
            preserveScroll: true,
            onSuccess: () => setDeleteModal({ isOpen: false, jadwalId: null })
        });
    }
};

const handleDragStart = (e: React.DragEvent<HTMLElement>, item: KisiKisiTugas) => {
    setDraggedItem(item);
    e.dataTransfer.effectAllowed = 'move';
    const img = new Image();
    img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    e.dataTransfer.setDragImage(img, 0, 0);
};

const handleDragOver = (e: React.DragEvent<HTMLElement>) => {
    e.preventDefault(); 
    e.dataTransfer.dropEffect = 'move';
};

const handleDrop = (e: React.DragEvent<HTMLElement>, waktuId: number, kelasId: number | null, expectedKodeTugas: string) => {
    e.preventDefault();
    
    if (!draggedItem) return;

    if (draggedItem.jenis_tugas.kode !== expectedKodeTugas) {
        showToast(`Area ini untuk ${expectedKodeTugas}, tapi Anda menarik kartu ${draggedItem.jenis_tugas.kode}.`, 'error');
        setDraggedItem(null);
        return;
    }

    router.post('/jadwal-guru/pembagian/assign', {
        kisi_kisi_tugas_id: draggedItem.id,
        waktu_pelajaran_id: waktuId,
        hari: hariAktif
    }, {
        preserveScroll: true,
        onSuccess: () => setDraggedItem(null),
    });
};

const filteredKisiKisi = kisiKisiTugas.filter(kisi => {
    if (kisi.sisa_jam_tersedia <= 0) return false; 
    
    const namaGuru = (kisi.guru?.user?.name || kisi.guru?.name || kisi.guru?.kode_guru || '').toLowerCase();
    const mapel = (kisi.mata_pelajaran?.nama || kisi.keterangan || '').toLowerCase();
    const keyword = searchTerm.toLowerCase();
    
    const matchSearch = namaGuru.includes(keyword) || mapel.includes(keyword);
    const matchType = filterType[kisi.jenis_tugas.kode];
    
    return matchSearch && matchType;
});

const getJadwalDiSlot = (waktuId: number, kelasId: number | null, jenisTugasKode: string) => {
    return jadwalTerplot.find(j => 
        j.hari === hariAktif && 
        j.waktu_pelajaran_id === waktuId && 
        (j.kelas_id === kelasId || (j.kelas_id === null && kelasId === null)) &&
        j.jenis_tugas.kode === jenisTugasKode
    );
};

const getGuruName = (guru?: Guru) => {
    return guru?.user?.name || guru?.name || guru?.kode_guru || 'Tanpa Nama';
};

return (
    <div className="bg-[#FAF7F2] text-[#3A322C] font-sans antialiased flex h-screen overflow-hidden">
        <Head title="Pembagian Jadwal Mengajar" />

        {toast.show && (
            <div className={`fixed top-6 left-1/2 transform -translate-x-1/2 z-50 px-6 py-3 rounded-lg shadow-lg flex items-center gap-3 transition-all animate-bounce ${toast.type === 'error' ? 'bg-red-100 text-red-800 border border-red-200' : 'bg-green-100 text-green-800 border border-green-200'}`}>
                {toast.type === 'error' ? '⚠️' : '✅'} 
                <span className="font-medium text-sm">{toast.message}</span>
            </div>
        )}

        {deleteModal.isOpen && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm">
                <div className="bg-white rounded-xl shadow-xl w-[400px] p-6 text-center transform scale-100 transition-all">
                    <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span className="text-2xl">🗑️</span>
                    </div>
                    <h3 className="text-lg font-bold text-gray-900 mb-2">Tarik Kembali Jadwal?</h3>
                    <p className="text-sm text-gray-500 mb-6">Jadwal ini akan dicabut dari kalender dan alokasi jamnya akan dikembalikan ke daftar penugasan guru.</p>
                    <div className="flex gap-3 justify-center">
                        <button onClick={() => setDeleteModal({ isOpen: false, jadwalId: null })} className="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 transition-colors">Batal</button>
                        <button onClick={executeDelete} className="px-5 py-2.5 rounded-lg text-sm font-medium text-white bg-red-600 hover:bg-red-700 transition-colors shadow-sm">Ya, Tarik Jadwal</button>
                    </div>
                </div>
            </div>
        )}

        <aside className="w-64 bg-white border-r border-gray-200 flex flex-col z-20 shadow-sm shrink-0">
            <div className="p-6 border-b border-gray-200">
                <h1 className="text-xl font-bold text-[#7A5738] leading-tight">SD Plus<br/>Gembala Baik</h1>
                <p className="text-xs text-gray-500 mt-1">Manajemen Akademik</p>
            </div>
            <nav className="flex-1 p-4 space-y-1 overflow-y-auto">
                <div className="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 mt-6">Modul Jadwal Guru</div>
                <a href="#" className="flex items-center px-3 py-2 text-gray-600 hover:bg-gray-50 rounded-md text-sm font-medium border-l-4 border-transparent transition-colors">Waktu Pelajaran</a>
                <a href="#" className="flex items-center px-3 py-2 text-gray-600 hover:bg-gray-50 rounded-md text-sm font-medium border-l-4 border-transparent transition-colors">Kisi-Kisi Tugas</a>
                <a href="#" className="flex items-center px-3 py-2 bg-[#A9784F]/10 text-[#7A5738] rounded-md text-sm font-medium border-l-4 border-[#A9784F] transition-colors shadow-sm">Pembagian Jadwal</a>
            </nav>
        </aside>

        <main className="flex-1 flex flex-col overflow-hidden h-full">
            <header className="bg-white border-b border-gray-200 px-8 py-5 flex flex-col shadow-sm shrink-0 z-10">
                <div className="flex justify-between items-center w-full mb-4">
                    <div>
                        <h2 className="text-2xl font-bold text-[#7A5738]">Pembagian Jadwal Mengajar</h2>
                        <p className="text-sm text-gray-500 mt-1">Seret kartu dari kanan ke sel yang tersedia di kalender.</p>
                    </div>
                    <div className="flex gap-2 bg-gray-100 p-1 rounded-lg border border-gray-200 shadow-inner">
                        {daftarHari.map(hari => (
                            <button 
                                key={hari.id}
                                onClick={() => setHariAktif(hari.id)}
                                className={`px-5 py-2 text-sm font-bold rounded-md uppercase transition-all duration-200 ${hariAktif === hari.id ? 'bg-[#A9784F] text-white shadow-md transform scale-[1.02]' : 'bg-transparent text-gray-500 hover:text-gray-800'}`}
                            >
                                {hari.name}
                            </button>
                        ))}
                    </div>
                </div>
            </header>

            <div className="flex-1 flex overflow-hidden">
                <div className="flex-1 overflow-auto p-6 relative custom-scrollbar">
                    {draggedItem && (
                        <div className="absolute inset-0 z-0 bg-[#A9784F]/5 rounded-lg border-2 border-dashed border-[#A9784F]/30 pointer-events-none flex items-center justify-center">
                            <span className="text-2xl font-bold text-[#A9784F]/20 uppercase tracking-widest">Lepaskan Kartu di Slot yang Sesuai</span>
                        </div>
                    )}

                    <table className="min-w-max w-full text-sm border-separate border-spacing-0 shadow-sm bg-white rounded-xl relative z-10">
                        <thead>
                            <tr>
                                <th className="sticky top-0 left-0 z-30 bg-gray-100 border-b border-r border-gray-300 px-3 py-4 w-24 text-center font-bold text-gray-700 rounded-tl-xl bg-clip-padding">Waktu</th>
                                {daftarKelas.map(kelas => (
                                    <th key={kelas.id} className="sticky top-0 z-20 bg-white border-b border-r border-gray-200 px-4 py-3 font-bold text-[#3A322C] w-64 text-center bg-clip-padding">
                                        <div className="text-base">Kelas {kelas.nama_kelas}</div>
                                        <div className="text-[10px] font-normal text-gray-400 mt-1 uppercase tracking-wider">Jenjang {kelas.jenjang}</div>
                                    </th>
                                ))}
                                <th className="sticky top-0 z-20 bg-purple-50/80 backdrop-blur border-b border-purple-200 px-4 py-3 font-bold text-purple-800 w-64 text-center rounded-tr-xl bg-clip-padding">
                                    <div className="text-base">Area Piket</div>
                                    <div className="text-[10px] font-normal text-purple-500 mt-1 uppercase tracking-wider">Tidak Terikat Kelas</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {waktuPelajaran.map((waktu, index) => {
                                const isLastRow = index === waktuPelajaran.length - 1;
                                return (
                                    <tr key={waktu.id} className="hover:bg-gray-50/50 transition-colors">
                                        <td className={`sticky left-0 z-20 bg-white border-r border-b border-gray-200 px-2 py-3 text-center align-top bg-clip-padding ${isLastRow ? 'rounded-bl-xl' : ''}`}>
                                            {waktu.jenis_kegiatan === 'kbm' ? (
                                                <>
                                                    <div className="font-black text-lg text-[#3A322C]">{waktu.jam_ke}</div>
                                                    <div className="text-[11px] font-medium text-gray-400 mt-1">{waktu.jam_mulai?.substring(0,5)}<br/>{waktu.jam_selesai?.substring(0,5)}</div>
                                                </>
                                            ) : (
                                                <div className="flex flex-col h-full justify-center">
                                                    <div className="text-xs font-bold text-orange-600 uppercase">{waktu.nama}</div>
                                                    <div className="text-[11px] font-medium text-orange-400 mt-1">{waktu.jam_mulai?.substring(0,5)}<br/>{waktu.jam_selesai?.substring(0,5)}</div>
                                                </div>
                                            )}
                                        </td>

                                        {daftarKelas.map(kelas => {
                                            const jadwalUtama = getJadwalDiSlot(waktu.id, kelas.id, 'utama');
                                            const jadwalDamping = getJadwalDiSlot(waktu.id, kelas.id, 'damping');
                                            const isEligible = kelas.jenjang >= waktu.jenjang_min && kelas.jenjang <= waktu.jenjang_max;

                                            return (
                                                <td key={`${waktu.id}-${kelas.id}`} className="border-b border-r border-gray-100 p-2 align-top relative">
                                                    {waktu.jenis_kegiatan === 'kbm' ? (
                                                        isEligible ? (
                                                            <div className="space-y-1.5 h-full">
                                                                {/* Dropzone UTAMA */}
                                                                {jadwalUtama ? (
                                                                    <div className="w-full p-2.5 bg-blue-50 border border-blue-200 rounded-lg relative group shadow-sm hover:shadow-md transition-shadow">
                                                                        <button onClick={() => confirmDelete(jadwalUtama.id)} className="absolute -top-2 -right-2 bg-red-100 text-red-600 border border-red-200 rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow-sm hover:bg-red-600 hover:text-white pb-0.5">×</button>
                                                                        <div className="font-bold text-blue-900 text-sm">{getGuruName(jadwalUtama.guru)}</div>
                                                                        <div className="text-xs text-blue-700 font-medium mt-1 bg-blue-100/50 inline-block px-1.5 py-0.5 rounded">{jadwalUtama.kisi_kisi_tugas?.mata_pelajaran?.nama || jadwalUtama.kisi_kisi_tugas?.keterangan || 'Mengajar'}</div>
                                                                    </div>
                                                                ) : (
                                                                    <div 
                                                                        onDragOver={handleDragOver}
                                                                        onDrop={(e) => handleDrop(e, waktu.id, kelas.id, 'utama')}
                                                                        className={`border-2 border-dashed rounded-lg flex flex-col items-center justify-center cursor-pointer transition-all min-h-[64px] ${draggedItem?.jenis_tugas?.kode === 'utama' ? 'border-blue-400 bg-blue-50/50 text-blue-500 scale-[1.02]' : 'border-gray-200 text-gray-400 hover:bg-gray-50 hover:border-blue-300'}`}
                                                                    >
                                                                        <span className="text-xs font-bold uppercase tracking-wider">+ Utama</span>
                                                                    </div>
                                                                )}

                                                                {/* Dropzone DAMPING (Aturan PRD: Hanya untuk Kelas 1 dan 2) */}
                                                                {kelas.jenjang <= 2 && (
                                                                    jadwalDamping ? (
                                                                        <div className="w-full p-2 bg-orange-50 border border-orange-200 rounded-lg relative group shadow-sm hover:shadow-md transition-shadow">
                                                                            <button onClick={() => confirmDelete(jadwalDamping.id)} className="absolute -top-2 -right-2 bg-red-100 text-red-600 border border-red-200 rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow-sm hover:bg-red-600 hover:text-white pb-0.5">×</button>
                                                                            <div className="flex justify-between items-center">
                                                                                <div className="font-bold text-orange-900 text-[13px]">{getGuruName(jadwalDamping.guru)}</div>
                                                                                <div className="text-[10px] font-bold uppercase tracking-wider text-orange-600 bg-orange-100 px-1.5 py-0.5 rounded">Damping</div>
                                                                            </div>
                                                                        </div>
                                                                    ) : (
                                                                        <div 
                                                                            onDragOver={handleDragOver}
                                                                            onDrop={(e) => handleDrop(e, waktu.id, kelas.id, 'damping')}
                                                                            className={`border-2 border-dashed rounded-lg flex items-center justify-center cursor-pointer transition-all min-h-[36px] ${draggedItem?.jenis_tugas?.kode === 'damping' ? 'border-orange-400 bg-orange-50/50 text-orange-500 scale-[1.02]' : 'border-gray-200/60 text-gray-300 hover:bg-gray-50 hover:border-orange-300 hover:text-orange-400'}`}
                                                                        >
                                                                            <span className="text-[10px] font-bold uppercase tracking-wider">+ Damping</span>
                                                                        </div>
                                                                    )
                                                                )}
                                                            </div>
                                                        ) : (
                                                            <div className="flex items-center justify-center h-full min-h-[60px] bg-gray-100/50 rounded-lg">
                                                                <span className="text-[10px] text-gray-400 font-medium">Bukan Sesi Kelas Ini</span>
                                                            </div>
                                                        )
                                                    ) : (
                                                        <div className="flex items-center justify-center h-full min-h-[40px] bg-orange-50/30 rounded-lg">
                                                            <span className="text-[10px] text-orange-400 font-bold uppercase tracking-widest">{waktu.jenis_kegiatan}</span>
                                                        </div>
                                                    )}
                                                </td>
                                            );
                                        })}

                                        {/* Kolom Khusus Area Piket */}
                                        <td className={`border-b border-gray-100 p-2 bg-purple-50/30 align-top ${isLastRow ? 'rounded-br-xl' : ''}`}>
                                            {(() => {
                                                const jadwalPiket = getJadwalDiSlot(waktu.id, null, 'piket');
                                                if (waktu.jenis_kegiatan !== 'kbm') {
                                                    return <div className="h-full min-h-[40px] flex items-center justify-center opacity-40"><span className="text-xs font-bold text-purple-300">-</span></div>;
                                                }
                                                return jadwalPiket ? (
                                                    <div className="w-full h-full p-3 bg-purple-100 border border-purple-300 rounded-lg relative group shadow-sm hover:shadow-md transition-shadow">
                                                        <button onClick={() => confirmDelete(jadwalPiket.id)} className="absolute -top-2 -right-2 bg-red-100 text-red-600 border border-red-200 rounded-full w-6 h-6 flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow-sm hover:bg-red-600 hover:text-white pb-0.5">×</button>
                                                        <div className="font-bold text-purple-900 text-sm mb-1">{getGuruName(jadwalPiket.guru)}</div>
                                                        <div className="text-xs text-purple-700 bg-purple-200/50 inline-block px-1.5 py-0.5 rounded">{jadwalPiket.kisi_kisi_tugas?.keterangan || 'Tugas Piket'}</div>
                                                    </div>
                                                ) : (
                                                    <div 
                                                        onDragOver={handleDragOver}
                                                        onDrop={(e) => handleDrop(e, waktu.id, null, 'piket')}
                                                        className={`h-full min-h-[108px] border-2 border-dashed rounded-lg flex items-center justify-center cursor-pointer transition-all ${draggedItem?.jenis_tugas?.kode === 'piket' ? 'border-purple-400 bg-purple-100/50 text-purple-600 scale-[1.02]' : 'border-purple-200 text-purple-300 hover:bg-purple-50 hover:border-purple-400 hover:text-purple-500'}`}
                                                    >
                                                        <span className="text-xs font-bold uppercase tracking-wider">+ Piket</span>
                                                    </div>
                                                );
                                            })()}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <aside className="w-[320px] bg-white border-l border-gray-200 flex flex-col shrink-0 z-20 shadow-[-10px_0_20px_rgba(0,0,0,0.03)]">
                    <div className="p-5 border-b border-gray-200 bg-gray-50 flex flex-col gap-4">
                        <div>
                            <h3 className="font-bold text-[#3A322C] text-lg">Kartu Penugasan</h3>
                            <p className="text-xs text-gray-500 font-medium mt-1"><span className="text-[#A9784F] font-bold">{filteredKisiKisi.length}</span> kartu tugas siap di-plot</p>
                        </div>
                        
                        <div className="relative">
                            <span className="absolute left-3 top-2.5 text-gray-400 text-sm">🔍</span>
                            <input 
                                type="text" 
                                placeholder="Cari guru atau mapel..." 
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-9 pr-3 py-2 bg-white border border-gray-300 rounded-lg text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-[#A9784F]/50 focus:border-[#A9784F]"
                            />
                        </div>

                        <div className="flex items-center gap-4 text-[11px] font-bold text-gray-500 uppercase tracking-wider bg-white py-2 px-3 rounded-lg border border-gray-200 shadow-sm justify-center">
                            {['utama', 'damping', 'piket'].map(type => (
                                <label key={type} className="flex items-center gap-1.5 cursor-pointer hover:text-[#3A322C] transition-colors">
                                    <input 
                                        type="checkbox" 
                                        checked={filterType[type]} 
                                        onChange={() => setFilterType({...filterType, [type]: !filterType[type]})}
                                        className={`w-3.5 h-3.5 rounded border-gray-300 focus:ring-0 ${type === 'utama' ? 'text-blue-600' : type === 'damping' ? 'text-orange-600' : 'text-purple-600'}`}
                                    /> {type}
                                </label>
                            ))}
                        </div>
                    </div>

                    <div className="flex-1 overflow-y-auto p-5 custom-scrollbar bg-gray-50/30">
                        <div className="space-y-3">
                            {filteredKisiKisi.map(kisi => (
                                <div 
                                    key={kisi.id}
                                    draggable
                                    onDragStart={(e) => handleDragStart(e, kisi)}
                                    onDragEnd={() => setDraggedItem(null)}
                                    className={`bg-white border-2 rounded-xl p-4 shadow-sm hover:shadow-lg transition-all cursor-move ${
                                        draggedItem?.id === kisi.id ? 'opacity-50 scale-95 border-gray-300' : 
                                        kisi.jenis_tugas.kode === 'utama' ? 'border-blue-100 hover:border-blue-400' :
                                        kisi.jenis_tugas.kode === 'damping' ? 'border-orange-100 hover:border-orange-400' :
                                        'border-purple-100 hover:border-purple-400'
                                    }`}
                                >
                                    <div className="flex items-center justify-between mb-2">
                                        <span className={`font-black text-[15px] ${
                                            kisi.jenis_tugas.kode === 'utama' ? 'text-blue-900' :
                                            kisi.jenis_tugas.kode === 'damping' ? 'text-orange-900' : 'text-purple-900'
                                        }`}>
                                            {getGuruName(kisi.guru)}
                                        </span>
                                        <span className={`text-[10px] px-2 py-1 rounded-md font-bold uppercase tracking-wider ${
                                            kisi.jenis_tugas.kode === 'utama' ? 'bg-blue-100 text-blue-700' :
                                            kisi.jenis_tugas.kode === 'damping' ? 'bg-orange-100 text-orange-700' : 'bg-purple-100 text-purple-700'
                                        }`}>
                                            {kisi.jenis_tugas.kode}
                                        </span>
                                    </div>
                                    <div className="text-sm font-medium text-gray-700 mb-3 bg-gray-50 px-2 py-1.5 rounded-lg border border-gray-100">
                                        {kisi.mata_pelajaran?.nama || kisi.keterangan || 'Tugas Spesifik'}
                                    </div>
                                    <div className="flex justify-between items-center text-xs pt-2 border-t border-gray-100">
                                        <span className="text-gray-500 font-medium">Sisa Alokasi Slot:</span>
                                        <span className="font-black text-lg text-[#A9784F]">{kisi.sisa_jam_tersedia}</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                        
                        {filteredKisiKisi.length === 0 && (
                            <div className="text-center bg-white border border-gray-200 rounded-xl p-6 mt-4 shadow-sm">
                                <span className="text-3xl mb-2 block">📭</span>
                                <p className="text-sm font-medium text-gray-500">Semua kartu pada kriteria ini sudah selesai di-plot (kuota habis).</p>
                            </div>
                        )}
                    </div>
                </aside>
            </div>
        </main>
    </div>
);


}