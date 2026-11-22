import { Head, Link, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import KonfirmasiTransferController from '@/actions/App/Domains/LesEkskul/Http/Controllers/KonfirmasiTransferController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { namaBulan, rupiah } from '@/lib/format';
import { tagihan as halamanTagihan } from '@/routes/ortu';

type Props = {
    siswa: { id: number; nama: string; kelas: string | null };
    tagihan: { id: number; kegiatan: string; bulan: string; sisa: number }[];
    total: number;
    berita: string; // usulan berita transfer: nama depan + kelas + kegiatan & bulan
    rekening: { bank: string | null; nomor: string; atas_nama: string | null } | null;
    adaWhatsApp: boolean;
    hariIni: string;
};

// Halaman Pembayaran orang tua: transfer manual ke rekening sekolah, lalu "Saya sudah bayar" (FR-15b, Ringkasan v1.11 #41).
// Nominal selalu total pas tagihan yang dipilih supaya mudah dicocokkan TU dengan mutasi
export default function Bayar({ siswa, tagihan, total, berita, rekening, adaWhatsApp, hariIni }: Props) {
    const form = useForm({
        siswa_id: siswa.id,
        tagihan_ids: tagihan.map((t) => t.id),
        tanggal_transfer: hariIni,
        nama_pengirim: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function kirim(e: React.FormEvent) {
        e.preventDefault();
        form.post(KonfirmasiTransferController.ajukan.url());
    }

    return (
        <>
            <Head title="Pembayaran" />

            <form onSubmit={kirim} className="flex flex-col gap-4">
                <div>
                    <h1 className="text-lg font-semibold">Pembayaran</h1>
                    <p className="text-sm text-muted-foreground">
                        {siswa.nama}
                        {siswa.kelas && ` · Kelas ${siswa.kelas}`}
                    </p>
                </div>

                <Card className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="text-base">Tagihan yang dibayar</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-2 px-4 text-sm">
                        <ul className="divide-y">
                            {tagihan.map((t) => (
                                <li key={t.id} className="flex justify-between gap-3 py-1.5">
                                    <span>
                                        {t.kegiatan} <span className="text-muted-foreground">– {namaBulan(t.bulan)}</span>
                                    </span>
                                    <span className="tabular-nums">{rupiah(t.sisa)}</span>
                                </li>
                            ))}
                        </ul>
                        <BarisSalin label="Total transfer" nilai={rupiah(total)} salin={String(total)} besar />
                        <p className="text-xs text-muted-foreground">Transfer tepat sebesar total ini supaya mudah dicocokkan TU.</p>
                    </CardContent>
                </Card>

                <Card className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="text-base">Transfer ke rekening sekolah</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 px-4 text-sm">
                        {rekening ? (
                            <div className="rounded-md bg-muted/60 p-3">
                                <p className="text-xs text-muted-foreground">{rekening.bank}</p>
                                <BarisSalin nilai={rekening.nomor} salin={rekening.nomor} mono />
                                <p className="text-xs text-muted-foreground">a.n. {rekening.atas_nama}</p>
                            </div>
                        ) : (
                            <p className="text-muted-foreground">Nomor rekening sekolah dapat ditanyakan ke TU.</p>
                        )}
                        <div className="rounded-md border p-3">
                            <BarisSalin label="Isi berita/catatan transfer" nilai={berita} salin={berita} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Nama depan, kelas, kegiatan, dan bulan. Bila kolom berita di aplikasi bank Anda terbatas, tulis sebisanya.
                            </p>
                        </div>
                        <p className="text-muted-foreground">
                            Setelah transfer, isi data di bawah lalu tekan <strong>Saya sudah bayar</strong>.{' '}
                            {adaWhatsApp
                                ? 'Setelah itu kirim bukti transfer ke Tata Usaha lewat tombol WhatsApp yang muncul di halaman Tagihan.'
                                : 'Setelah itu kirim bukti transfer ke Tata Usaha.'}
                        </p>
                    </CardContent>
                </Card>

                <Card className="gap-3 py-4">
                    <CardContent className="flex flex-col gap-3 px-4">
                        <div className="grid gap-2">
                            <Label htmlFor="tanggal_transfer">Tanggal transfer</Label>
                            <Input
                                id="tanggal_transfer"
                                type="date"
                                max={hariIni}
                                value={form.data.tanggal_transfer}
                                onChange={(e) => form.setData('tanggal_transfer', e.target.value)}
                            />
                            <InputError message={errors.tanggal_transfer} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="nama_pengirim">Nama pemilik rekening pengirim (opsional)</Label>
                            <Input
                                id="nama_pengirim"
                                placeholder="Bila berbeda dengan nama Anda"
                                value={form.data.nama_pengirim}
                                onChange={(e) => form.setData('nama_pengirim', e.target.value)}
                            />
                            <InputError message={errors.nama_pengirim} />
                        </div>
                        <InputError message={errors.tagihan ?? errors.tagihan_ids} />
                        <Button type="submit" disabled={form.processing}>
                            Saya sudah bayar
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href={halamanTagihan()}>Kembali</Link>
                        </Button>
                    </CardContent>
                </Card>
            </form>
        </>
    );
}

// Satu nilai dengan tombol salin (nominal, nomor rekening, berita transfer)
function BarisSalin({ label, nilai, salin, mono, besar }: { label?: string; nilai: string; salin: string; mono?: boolean; besar?: boolean }) {
    const [tersalin, setTersalin] = useState(false);

    function salinTeks() {
        void navigator.clipboard?.writeText(salin).then(() => {
            setTersalin(true);
            setTimeout(() => setTersalin(false), 2000);
        });
    }

    return (
        <div>
            {label && <p className="text-xs text-muted-foreground">{label}</p>}
            <div className="flex items-center justify-between gap-2">
                <p className={[mono ? 'font-mono tracking-wide' : '', besar || mono ? 'text-lg font-semibold' : 'font-medium', 'break-words'].join(' ')}>
                    {nilai}
                </p>
                <Button type="button" variant="ghost" size="sm" onClick={salinTeks}>
                    <Copy className="size-4" />
                    {tersalin ? 'Tersalin' : 'Salin'}
                </Button>
            </div>
        </div>
    );
}
