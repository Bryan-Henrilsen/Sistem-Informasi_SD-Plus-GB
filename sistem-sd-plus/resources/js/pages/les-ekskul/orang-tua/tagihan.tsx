import { Head } from '@inertiajs/react';
import { Copy, Landmark } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';

type StatusTagihan = 'lunas' | 'menunggu_verifikasi' | 'menunggu_online' | 'sebagian' | 'belum';
type Tagihan = {
    id: number;
    bulan: string;
    kegiatan: string;
    nominal: number;
    diskon: number;
    harus_dibayar: number;
    dibayar: number;
    sisa: number;
    status: StatusTagihan;
    tunggakan: boolean;
};
type Ringkasan = { tunggakan: number; bulan_ini: number; menunggu_verifikasi: number };
type Anak = {
    id: number;
    nama: string;
    kelas: string | null;
    ringkasan: Ringkasan;
    tagihan: Tagihan[];
    ditolak: { id: number; tanggal: string; nominal: number; alasan: string | null }[];
};
type Rekening = { bank: string | null; nomor: string; atas_nama: string | null };

const LABEL: Record<StatusTagihan, { teks: string; kelas: string }> = {
    lunas: { teks: 'Lunas', kelas: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' },
    menunggu_verifikasi: { teks: 'Menunggu verifikasi TU', kelas: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' },
    menunggu_online: { teks: 'Menunggu pembayaran online', kelas: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' },
    sebagian: { teks: 'Dibayar sebagian', kelas: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    belum: { teks: 'Belum dibayar', kelas: 'bg-muted text-muted-foreground' },
};

// Tagihan & status pembayaran per anak (FR-15) dan cara bayar transfer manual (FR-32; Ringkasan v1.11 #38)
export default function TagihanOrangTua({ anak, rekening, waTu }: { anak: Anak[]; rekening: Rekening | null; waTu: string | null }) {
    return (
        <>
            <Head title="Tagihan" />

            <div className="flex flex-col gap-4">
                <h1 className="text-lg font-semibold">Tagihan</h1>

                {anak.length === 0 && <p className="text-sm text-muted-foreground">Tambahkan anak Anda dulu di menu Anak untuk melihat tagihannya.</p>}

                {anak.map((a) => (
                    <TagihanAnak key={a.id} anak={a} />
                ))}

                <CaraBayar rekening={rekening} waTu={waTu} />
            </div>
        </>
    );
}

function TagihanAnak({ anak }: { anak: Anak }) {
    // Kelompokkan per bulan tagihan (data sudah urut terbaru dulu)
    const perBulan = anak.tagihan.reduce<Record<string, Tagihan[]>>((acc, t) => {
        (acc[t.bulan] ??= []).push(t);

        return acc;
    }, {});

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">{anak.nama}</CardTitle>
                <p className="text-sm text-muted-foreground">{anak.kelas ? `Kelas ${anak.kelas}` : 'Belum terdaftar di kelas tahun ini'}</p>
            </CardHeader>
            <CardContent className="flex flex-col gap-4 px-4">
                <div className="grid grid-cols-2 gap-2">
                    <Angka label="Tunggakan" nilai={anak.ringkasan.tunggakan} merah={anak.ringkasan.tunggakan > 0} />
                    <Angka label="Belum dibayar bulan ini" nilai={anak.ringkasan.bulan_ini} />
                </div>

                {anak.ditolak.length > 0 && (
                    <div className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                        <p className="font-medium">Transfer yang ditolak</p>
                        <ul className="mt-1 flex flex-col gap-1">
                            {anak.ditolak.map((d) => (
                                <li key={d.id}>
                                    {tanggal(d.tanggal)} · {rupiah(d.nominal)}
                                    {d.alasan && <span className="block text-xs">Alasan: {d.alasan}</span>}
                                </li>
                            ))}
                        </ul>
                        <p className="mt-1 text-xs">Tagihan terkait kembali belum dibayar. Hubungi TU bila ada pertanyaan.</p>
                    </div>
                )}

                {anak.tagihan.length === 0 && <p className="text-sm text-muted-foreground">Belum ada tagihan tahun ajaran ini.</p>}

                {Object.entries(perBulan).map(([bulan, daftar]) => (
                    <div key={bulan} className="flex flex-col gap-2">
                        <p className="text-sm font-medium">{namaBulan(bulan)}</p>
                        {daftar.map((t) => (
                            <div key={t.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                <div className="min-w-0">
                                    <p className="text-sm">{t.kegiatan}</p>
                                    <span className={cn('mt-1 inline-block rounded-full px-2 py-0.5 text-xs', LABEL[t.status].kelas)}>
                                        {LABEL[t.status].teks}
                                    </span>
                                    {t.tunggakan && <span className="ml-1 text-xs text-red-600 dark:text-red-400">Tunggakan</span>}
                                </div>
                                <div className="shrink-0 text-right text-sm tabular-nums">
                                    <p>{rupiah(t.harus_dibayar)}</p>
                                    {t.diskon > 0 && <p className="text-xs text-muted-foreground line-through">{rupiah(t.nominal)}</p>}
                                    {t.status === 'sebagian' && <p className="text-xs text-amber-700 dark:text-amber-400">sisa {rupiah(t.sisa)}</p>}
                                </div>
                            </div>
                        ))}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

// Pembayaran: cash di loket TU atau transfer manual (bukti dikirim ke TU, tidak diunggah ke aplikasi)
function CaraBayar({ rekening, waTu }: { rekening: Rekening | null; waTu: string | null }) {
    const [tersalin, setTersalin] = useState(false);

    function salin(nomor: string) {
        void navigator.clipboard?.writeText(nomor).then(() => {
            setTersalin(true);
            setTimeout(() => setTersalin(false), 2000);
        });
    }

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Landmark className="size-4" />
                    Cara membayar
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4 text-sm">
                <p>Bayar tunai di loket Tata Usaha (TU), atau transfer ke rekening sekolah.</p>
                {rekening ? (
                    <div className="rounded-md bg-muted/60 p-3">
                        <p className="text-xs text-muted-foreground">{rekening.bank}</p>
                        <div className="flex items-center justify-between gap-2">
                            <p className="font-mono text-lg tracking-wide">{rekening.nomor}</p>
                            <Button variant="ghost" size="sm" onClick={() => salin(rekening.nomor)}>
                                <Copy className="size-4" />
                                {tersalin ? 'Tersalin' : 'Salin'}
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">a.n. {rekening.atas_nama}</p>
                    </div>
                ) : (
                    <p className="text-muted-foreground">Nomor rekening sekolah dapat ditanyakan ke TU.</p>
                )}
                <p className="text-muted-foreground">
                    Setelah transfer, kirim bukti transfer ke TU{waTu ? ` (WhatsApp ${waTu})` : ''} dengan menyebutkan nama anak dan bulan yang dibayar. Status
                    tagihan berubah setelah TU memverifikasi.
                </p>
            </CardContent>
        </Card>
    );
}

function Angka({ label, nilai, merah }: { label: string; nilai: number; merah?: boolean }) {
    return (
        <div className="rounded-md border p-2.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('font-semibold tabular-nums', merah && 'text-red-600 dark:text-red-400')}>{rupiah(nilai)}</p>
        </div>
    );
}
