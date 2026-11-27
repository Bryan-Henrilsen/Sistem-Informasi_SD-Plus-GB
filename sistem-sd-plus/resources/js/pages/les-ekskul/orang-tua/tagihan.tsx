import { Head, router, usePage } from '@inertiajs/react';
import { Landmark, MessageCircle } from 'lucide-react';
import { useState } from 'react';
import KonfirmasiTransferController from '@/actions/App/Domains/LesEkskul/Http/Controllers/KonfirmasiTransferController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { bayar } from '@/routes/ortu';

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
    bisa_dipilih: boolean; // boleh dicentang untuk "Saya sudah bayar" (#41)
};
type Ringkasan = { tunggakan: number; bulan_ini: number; menunggu_verifikasi: number };
type Konfirmasi = {
    id: number;
    status: 'menunggu' | 'ditolak';
    nominal: number;
    tanggal_transfer: string;
    alasan: string | null;
    berita: string;
    whatsapp: string | null; // tautan wa.me dengan pesan terisi; null bila nomor TU belum diatur
};
type Anak = {
    id: number;
    nama: string;
    kelas: string | null;
    ringkasan: Ringkasan;
    tagihan: Tagihan[];
    ditolak: { id: number; tanggal: string; nominal: number; alasan: string | null }[];
    konfirmasi: Konfirmasi[];
    libur: { id: number; kegiatan: string; bulan: string; alasan: string }[]; // kegiatan tidak berlangsung (FR-23, #48)
};
type Rekening = { bank: string | null; nomor: string; atas_nama: string | null };

const LABEL: Record<StatusTagihan, { teks: string; kelas: string }> = {
    lunas: { teks: 'Lunas', kelas: 'bg-sekolah-sukses/15 text-sekolah-sukses' },
    menunggu_verifikasi: { teks: 'Menunggu verifikasi TU', kelas: 'bg-sekolah-info/15 text-sekolah-info' },
    menunggu_online: { teks: 'Menunggu pembayaran online', kelas: 'bg-sekolah-info/15 text-sekolah-info' },
    sebagian: { teks: 'Dibayar sebagian', kelas: 'bg-sekolah-tertunda/15 text-sekolah-tertunda' },
    belum: { teks: 'Belum dibayar', kelas: 'bg-muted text-muted-foreground' },
};

// Tagihan & status pembayaran per anak (FR-15). Orang tua mencentang tagihan lalu lanjut ke halaman Pembayaran
// untuk transfer manual + "Saya sudah bayar" (FR-15b, Ringkasan v1.11 #41)
export default function TagihanOrangTua({ anak, rekening }: { anak: Anak[]; rekening: Rekening | null }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title="Tagihan" />

            <div className="flex flex-col gap-4">
                <h1 className="text-lg font-semibold">Tagihan</h1>

                {(errors.tagihan || errors.siswa) && (
                    <p className="rounded-md border border-sekolah-bahaya/40 bg-sekolah-bahaya/10 p-3 text-sm text-sekolah-bahaya">
                        {errors.tagihan ?? errors.siswa}
                    </p>
                )}

                {anak.length === 0 && <p className="text-sm text-muted-foreground">Tambahkan anak Anda dulu di menu Anak untuk melihat tagihannya.</p>}

                {anak.map((a) => (
                    <TagihanAnak key={a.id} anak={a} />
                ))}

                <CaraBayar rekening={rekening} />
            </div>
        </>
    );
}

function TagihanAnak({ anak }: { anak: Anak }) {
    const [dipilih, setDipilih] = useState<number[]>([]);

    // Kelompokkan per bulan tagihan (data sudah urut terbaru dulu)
    const perBulan = anak.tagihan.reduce<Record<string, Tagihan[]>>((acc, t) => {
        (acc[t.bulan] ??= []).push(t);

        return acc;
    }, {});
    const total = anak.tagihan.filter((t) => dipilih.includes(t.id)).reduce((jumlah, t) => jumlah + t.sisa, 0);

    function centang(id: number, ya: boolean) {
        setDipilih((lama) => (ya ? [...lama, id] : lama.filter((x) => x !== id)));
    }

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

                {anak.konfirmasi.map((k) => (
                    <KartuKonfirmasi key={k.id} konfirmasi={k} />
                ))}

                {anak.ditolak.length > 0 && (
                    <div className="rounded-md border border-sekolah-bahaya/40 bg-sekolah-bahaya/10 p-3 text-sm text-sekolah-bahaya">
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

                {anak.libur.length > 0 && (
                    <div className="rounded-md border border-sekolah-info/40 bg-sekolah-info/10 p-3 text-sm text-sekolah-info">
                        <p className="font-medium">Kegiatan tidak berlangsung</p>
                        <ul className="mt-1 flex flex-col gap-1">
                            {anak.libur.map((l) => (
                                <li key={l.id}>
                                    {l.kegiatan} · <span className="capitalize">{namaBulan(l.bulan)}</span>
                                    <span className="block text-xs">Alasan: {l.alasan}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-1 text-xs">Bulan itu tidak ditagih. Pembayaran yang sudah masuk untuk bulan itu dipakai untuk bulan berikutnya, atau dikembalikan TU bila tidak ada lagi bulan yang ditagih.</p>
                    </div>
                )}

                {anak.tagihan.length === 0 && <p className="text-sm text-muted-foreground">Belum ada tagihan tahun ajaran ini.</p>}

                {anak.tagihan.some((t) => t.bisa_dipilih) && (
                    <p className="text-xs text-muted-foreground">Centang tagihan yang akan dibayar, lalu tekan "Lanjut ke pembayaran".</p>
                )}

                {Object.entries(perBulan).map(([bulan, daftar]) => (
                    <div key={bulan} className="flex flex-col gap-2">
                        <p className="text-sm font-medium">{namaBulan(bulan)}</p>
                        {daftar.map((t) => (
                            <label
                                key={t.id}
                                className={cn('flex items-start gap-3 rounded-md border p-3', t.bisa_dipilih && 'cursor-pointer', dipilih.includes(t.id) && 'border-primary bg-muted/40')}
                            >
                                {t.bisa_dipilih && (
                                    <Checkbox className="mt-0.5" checked={dipilih.includes(t.id)} onCheckedChange={(v) => centang(t.id, v === true)} />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm">{t.kegiatan}</p>
                                    <span className={cn('mt-1 inline-block rounded-full px-2 py-0.5 text-xs', LABEL[t.status].kelas)}>{LABEL[t.status].teks}</span>
                                    {t.tunggakan && <span className="ml-1 text-xs text-sekolah-bahaya">Tunggakan</span>}
                                </div>
                                <div className="shrink-0 text-right text-sm tabular-nums">
                                    <p>{rupiah(t.harus_dibayar)}</p>
                                    {t.diskon > 0 && <p className="text-xs text-muted-foreground line-through">{rupiah(t.nominal)}</p>}
                                    {t.status === 'sebagian' && <p className="text-xs text-sekolah-tertunda">sisa {rupiah(t.sisa)}</p>}
                                </div>
                            </label>
                        ))}
                    </div>
                ))}

                {dipilih.length > 0 && (
                    // Tetap terlihat di bawah layar HP selama ada tagihan yang dicentang
                    <div className="sticky bottom-20 z-10 flex items-center justify-between gap-3 rounded-lg border bg-background p-3 shadow-md">
                        <div className="text-sm">
                            <p className="text-muted-foreground">{dipilih.length} tagihan dipilih</p>
                            <p className="font-semibold tabular-nums">{rupiah(total)}</p>
                        </div>
                        <Button onClick={() => router.get(bayar().url, { siswa: anak.id, tagihan: dipilih })}>Lanjut ke pembayaran</Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// Konfirmasi "Saya sudah bayar" yang menunggu TU (kirim bukti lewat WhatsApp, bisa dibatalkan) atau ditolak beserta alasannya
function KartuKonfirmasi({ konfirmasi: k }: { konfirmasi: Konfirmasi }) {
    if (k.status === 'ditolak') {
        return (
            <div className="rounded-md border border-sekolah-bahaya/40 bg-sekolah-bahaya/10 p-3 text-sm text-sekolah-bahaya">
                <p className="font-medium">Konfirmasi transfer ditolak TU</p>
                <p>
                    {tanggal(k.tanggal_transfer)} · {rupiah(k.nominal)} · {k.berita}
                </p>
                {k.alasan && <p className="text-xs">Alasan: {k.alasan}</p>}
                <p className="mt-1 text-xs">Tagihannya bisa dipilih lagi. Hubungi TU bila transfer sudah dilakukan.</p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-md border border-sekolah-info/40 bg-sekolah-info/10 p-3 text-sm text-sekolah-info">
            <p className="font-medium">Menunggu verifikasi TU</p>
            <p>
                Transfer {rupiah(k.nominal)} tanggal {tanggal(k.tanggal_transfer)} untuk {k.berita}.
            </p>
            <p className="text-xs">Mohon kirim bukti transfer ke Tata Usaha agar segera diverifikasi.</p>
            <div className="flex flex-wrap gap-2">
                {k.whatsapp && (
                    <Button size="sm" asChild>
                        <a href={k.whatsapp} target="_blank" rel="noreferrer">
                            <MessageCircle /> Kirim bukti ke TU via WhatsApp
                        </a>
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        if (confirm('Batalkan konfirmasi ini? Tagihannya bisa dipilih lagi.')) {
                            router.delete(KonfirmasiTransferController.batalkan.url(k.id), { preserveScroll: true });
                        }
                    }}
                >
                    Batalkan
                </Button>
            </div>
        </div>
    );
}

// Cara membayar fase sekarang: transfer manual ke rekening sekolah (pembayaran online & QRIS menyusul, #41)
function CaraBayar({ rekening }: { rekening: Rekening | null }) {
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Landmark className="size-4" />
                    Cara membayar
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 px-4 text-sm text-muted-foreground">
                <p>
                    Centang tagihan, tekan "Lanjut ke pembayaran", lalu transfer ke rekening {rekening?.bank ?? 'sekolah'} sesuai total yang tertera. Setelah
                    transfer, tekan "Saya sudah bayar" dan kirim bukti transfer ke TU lewat WhatsApp.
                </p>
                <p>Pembayaran tunai tetap bisa dilakukan di loket Tata Usaha.</p>
            </CardContent>
        </Card>
    );
}

function Angka({ label, nilai, merah }: { label: string; nilai: number; merah?: boolean }) {
    return (
        <div className="rounded-md border p-2.5">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className={cn('font-semibold tabular-nums', merah && 'text-sekolah-bahaya')}>{rupiah(nilai)}</p>
        </div>
    );
}
