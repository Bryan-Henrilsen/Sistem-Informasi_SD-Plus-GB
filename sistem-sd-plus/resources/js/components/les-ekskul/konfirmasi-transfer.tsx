import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import KonfirmasiTransferController from '@/actions/App/Domains/LesEkskul/Http/Controllers/KonfirmasiTransferController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { rupiah, tanggal, waktu } from '@/lib/format';
import { create } from '@/routes/les-ekskul/transaksi';

// Data dari KonfirmasiTransferService::daftarMenunggu (FR-15b, Ringkasan v1.11 #41)
export type KonfirmasiMenunggu = {
    id: number;
    siswa: { id: number; nama: string; kelas: string | null };
    pengaju: { nama: string; no_hp: string | null };
    nominal: number;
    tanggal_transfer: string;
    nama_pengirim: string | null;
    diajukan_at: string;
    tagihan: string[];
};

// Daftar "Saya sudah bayar" dari orang tua di halaman Transaksi (khusus TU): cek mutasi BCA, lalu Catat atau Tolak
export function DaftarKonfirmasi({ data }: { data: KonfirmasiMenunggu[] }) {
    const [ditolak, setDitolak] = useState<KonfirmasiMenunggu | null>(null);

    if (data.length === 0) {
        return null;
    }

    return (
        <Card className="gap-3 border-sky-300 py-4 dark:border-sky-800">
            <CardHeader className="px-4">
                <CardTitle className="text-base">Konfirmasi transfer dari orang tua ({data.length})</CardTitle>
                <p className="text-sm text-muted-foreground">
                    Orang tua menekan "Saya sudah bayar". Cocokkan dengan mutasi BCA dan bukti di WhatsApp, lalu catat menjadi transaksi atau tolak bila
                    transfernya tidak ada.
                </p>
            </CardHeader>
            <CardContent className="px-4">
                <ul className="divide-y rounded-md border">
                    {data.map((k) => (
                        <li key={k.id} className="flex flex-wrap items-start justify-between gap-3 p-3 text-sm">
                            <div className="min-w-0">
                                <p className="font-medium">
                                    {k.siswa.nama} {k.siswa.kelas && <span className="font-normal text-muted-foreground">· {k.siswa.kelas}</span>}
                                </p>
                                <p className="text-muted-foreground">{k.tagihan.join(', ')}</p>
                                <p className="text-xs text-muted-foreground">
                                    Transfer {tanggal(k.tanggal_transfer)}
                                    {k.nama_pengirim && ` · a.n. ${k.nama_pengirim}`} · diajukan {k.pengaju.nama}
                                    {k.pengaju.no_hp && ` (${k.pengaju.no_hp})`} {waktu(k.diajukan_at)}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium tabular-nums">{rupiah(k.nominal)}</span>
                                <Button size="sm" asChild>
                                    <Link href={create({ query: { konfirmasi: k.id } })}>Catat</Link>
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setDitolak(k)}>
                                    Tolak
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </CardContent>

            <Dialog open={ditolak !== null} onOpenChange={(buka) => !buka && setDitolak(null)}>
                <DialogContent>
                    {ditolak && (
                        <Form
                            {...KonfirmasiTransferController.tolak.form(ditolak.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDitolak(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>Tolak konfirmasi {rupiah(ditolak.nominal)}</DialogTitle>
                                        <DialogDescription>
                                            {ditolak.siswa.nama}. Alasannya ditampilkan ke orang tua, dan tagihannya bisa dipilih lagi untuk dibayar.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Input name="alasan" placeholder="Alasan, mis. transfer tidak ada di mutasi rekening" autoFocus />
                                    <InputError message={errors.alasan ?? (errors as Record<string, string>).konfirmasi} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setDitolak(null)}>
                                            Batal
                                        </Button>
                                        <Button type="submit" variant="destructive" disabled={processing}>
                                            Tolak konfirmasi
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </Card>
    );
}
