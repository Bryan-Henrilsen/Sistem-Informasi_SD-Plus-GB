import { Form, router } from '@inertiajs/react';
import TransaksiController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TransaksiController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import type { MetodeTransaksi, StatusTransaksi } from '@/types/les-ekskul';

export type TransferRingkas = {
    id: number;
    siswa: string;
    nominal: number;
    metode: MetodeTransaksi;
    status: StatusTransaksi;
    status_approval_bendahara: 'belum_dicek' | 'disetujui' | null;
};

// Tombol per transfer sesuai tahap dan role (FR-8 verifikasi TU, FR-8b persetujuan Bendahara, FR-8d tolak).
// Aturan yang sama dijaga lagi di TransaksiService; dipakai di halaman Transaksi dan Laporan Harian
export function AksiTransfer({
    t,
    roles,
    onTolak,
}: {
    t: TransferRingkas;
    roles: string[];
    onTolak: (t: TransferRingkas) => void;
}) {
    const tu = roles.includes('tu');
    const bendahara = roles.includes('bendahara');

    if (t.metode !== 'transfer' || t.status === 'ditolak' || t.status_approval_bendahara === 'disetujui' || (!tu && !bendahara)) {
        return null;
    }

    const kirim = (url: string) => router.post(url, {}, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-1">
            {t.status === 'pending' && tu && (
                <Button size="sm" onClick={() => kirim(TransaksiController.verifikasi.url(t.id))}>
                    Verifikasi
                </Button>
            )}
            {t.status === 'pending' && !tu && (
                // Bendahara menunggu TU: dua lapis pemeriksaan oleh orang berbeda (FR-8, FR-8b)
                <span className="text-xs text-muted-foreground">Menunggu verifikasi TU</span>
            )}
            {t.status === 'terverifikasi' && bendahara && (
                <Button size="sm" onClick={() => kirim(TransaksiController.setujui.url(t.id))}>
                    Setujui
                </Button>
            )}
            {t.status === 'terverifikasi' && !bendahara && (
                <span className="text-xs text-muted-foreground">Menunggu Bendahara</span>
            )}
            <Button size="sm" variant="outline" onClick={() => onTolak(t)}>
                Tolak
            </Button>
        </div>
    );
}

// Dialog alasan penolakan transfer (alasan wajib, FR-8d)
export function DialogTolakTransfer({ transaksi, onClose }: { transaksi: TransferRingkas | null; onClose: () => void }) {
    return (
        <Dialog open={transaksi !== null} onOpenChange={(buka) => !buka && onClose()}>
            <DialogContent>
                {transaksi && (
                    <Form
                        {...TransaksiController.tolak.form(transaksi.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Tolak transfer {rupiah(transaksi.nominal)}</DialogTitle>
                                    <DialogDescription>
                                        {transaksi.siswa}. Transaksi tetap tersimpan sebagai ditolak, tagihannya kembali
                                        belum lunas. Penolakan tidak bisa dibatalkan.
                                    </DialogDescription>
                                </DialogHeader>
                                <Input name="alasan" placeholder="Alasan, mis. tidak ada di mutasi rekening" autoFocus />
                                <InputError message={errors.alasan ?? errors.transaksi} />
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={onClose}>
                                        Batal
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Tolak transfer
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
