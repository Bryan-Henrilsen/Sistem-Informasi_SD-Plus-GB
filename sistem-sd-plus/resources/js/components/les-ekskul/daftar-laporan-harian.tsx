import type { ReactNode } from 'react';
import { LabelStatus } from '@/components/les-ekskul/label-status';
import { hariTanggal, rupiah, tanggal } from '@/lib/format';
import type { MetodeTransaksi, StatusTransaksi } from '@/types/les-ekskul';

export type TransaksiLaporan = {
    id: number;
    siswa: string;
    tanggal: string;
    terlambat: boolean;
    metode: MetodeTransaksi;
    nominal: number;
    status: StatusTransaksi;
    status_approval_bendahara: 'belum_dicek' | 'disetujui' | null;
    sumber_input: 'tu' | 'bendahara' | 'sistem';
};

export type LaporanHarianBaris = {
    id: number;
    tanggal: string;
    status: 'draft' | 'diajukan' | 'disetujui' | 'ditolak';
    jumlah: number;
    total: number;
    total_cash: number;
    total_transfer: number;
    diajukan_oleh: string | null;
    disetujui_oleh: string | null;
    alasan_ditolak: string | null;
    ditolak_oleh: string | null;
    transaksi: TransaksiLaporan[];
};

// Rekap per hari; setiap hari bisa dibuka untuk melihat transaksinya (FR-13). Total tanpa transfer yang ditolak
export function DaftarLaporanHarian({
    laporan,
    aksi,
    aksiTransaksi,
    terbuka = false,
}: {
    laporan: LaporanHarianBaris[];
    aksi?: (l: LaporanHarianBaris) => ReactNode; // tombol per laporan (ajukan, setujui, ...)
    aksiTransaksi?: (t: TransaksiLaporan) => ReactNode; // tombol per transfer (verifikasi, setujui, tolak)
    terbuka?: boolean; // rincian langsung dibuka, mis. mode "perlu tindakan saya"
}) {
    if (laporan.length === 0) {
        return (
            <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">
                Belum ada transaksi yang dicatat di periode ini.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            {laporan.map((l) => (
                <details key={l.id} className="group rounded-xl border" open={terbuka || undefined}>
                    <summary className="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-2 p-3 hover:bg-muted/50">
                        <span className="w-44 font-medium">
                            <span className="mr-1 inline-block text-muted-foreground transition-transform group-open:rotate-90">›</span>
                            {hariTanggal(l.tanggal)}
                        </span>
                        <span className="w-48 text-sm">
                            {rupiah(l.total)} <span className="text-muted-foreground">({l.jumlah} transaksi)</span>
                        </span>
                        <span className="flex-1 text-xs text-muted-foreground">
                            Cash {rupiah(l.total_cash)} · Transfer {rupiah(l.total_transfer)}
                            {l.diajukan_oleh && ` · diajukan ${l.diajukan_oleh}`}
                            {l.disetujui_oleh && l.status === 'disetujui' && ` · disetujui ${l.disetujui_oleh}`}
                        </span>
                        <LabelStatus status={l.status} />
                        {aksi && (
                            // Klik tombol tidak ikut membuka/menutup rincian
                            <span className="flex gap-1" onClick={(e) => e.preventDefault()}>
                                {aksi(l)}
                            </span>
                        )}
                        {l.alasan_ditolak && (
                            <span className="w-full text-xs text-rose-600">
                                Ditolak {l.ditolak_oleh}: {l.alasan_ditolak}
                            </span>
                        )}
                    </summary>

                    <div className="overflow-x-auto border-t">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2 pl-3">Siswa</th>
                                    <th className="p-2">Metode</th>
                                    <th className="p-2 text-right">Nominal</th>
                                    <th className="p-2">Status TU</th>
                                    <th className="p-2">Approval Bendahara</th>
                                    {aksiTransaksi && <th className="p-2" />}
                                </tr>
                            </thead>
                            <tbody>
                                {l.transaksi.map((t) => (
                                    <tr key={t.id} className="border-t">
                                        <td className="p-2 pl-3">
                                            {t.siswa}
                                            {t.terlambat && (
                                                <div className="text-xs text-amber-600">
                                                    Dicatat terlambat · uang masuk {tanggal(t.tanggal)}
                                                </div>
                                            )}
                                        </td>
                                        <td className="p-2 capitalize">
                                            {t.metode}
                                            {t.sumber_input === 'bendahara' && (
                                                <div className="text-xs text-muted-foreground normal-case">dari mutasi rekening</div>
                                            )}
                                        </td>
                                        <td className={`p-2 text-right ${t.status === 'ditolak' ? 'text-muted-foreground line-through' : ''}`}>
                                            {rupiah(t.nominal)}
                                        </td>
                                        <td className="p-2">
                                            <LabelStatus status={t.status} />
                                        </td>
                                        <td className="p-2">
                                            {t.metode === 'cash' ? (
                                                <span className="text-xs text-muted-foreground">Tidak berlaku (cash)</span>
                                            ) : t.status_approval_bendahara && t.status !== 'ditolak' ? (
                                                <LabelStatus status={t.status_approval_bendahara} />
                                            ) : (
                                                <span className="text-xs text-muted-foreground">–</span>
                                            )}
                                        </td>
                                        {aksiTransaksi && <td className="p-2">{aksiTransaksi(t)}</td>}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            ))}
        </div>
    );
}
