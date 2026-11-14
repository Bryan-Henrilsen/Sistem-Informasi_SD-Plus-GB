import { Form } from '@inertiajs/react';
import { useState } from 'react';
import PendaftaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PendaftaranController';
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
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';
import type { TipeKegiatan } from '@/types/les-ekskul';

export type PendaftaranDiubah = {
    id: number;
    siswa: { id: number; nama: string };
    kelas: string | null;
    jenjang: number | null;
    kegiatan: string;
    jenis: TipeKegiatan;
    biaya: number;
    induk_id: number | null;
};

export type SaranTanggal = { les: string; ekskul: string };

export type EkskulTujuan = { id: number; nama: string; biaya: number; jenjang_min: number | null; jenjang_max: number | null };

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// FR-10: berhentikan les/ekskul. Peringatan batas semester (ekskul) datang dari server; TU mengonfirmasi lalu mengisi alasan
export function DialogBerhenti({
    pendaftaran,
    saranTanggal,
    onClose,
}: {
    pendaftaran: PendaftaranDiubah | null;
    saranTanggal: SaranTanggal;
    onClose: () => void;
}) {
    const les = pendaftaran?.jenis === 'les_tambahan';

    return (
        <Dialog open={pendaftaran !== null} onOpenChange={(buka) => !buka && onClose()}>
            <DialogContent>
                {pendaftaran && (
                    <Form
                        {...PendaftaranController.berhenti.form(pendaftaran.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Berhentikan dari {pendaftaran.kegiatan}</DialogTitle>
                                    <DialogDescription>
                                        {pendaftaran.siswa.nama} ({pendaftaran.kelas}). Tagihan bulan yang tidak diikuti lagi
                                        dibatalkan; yang sudah dibayar dicatat sebagai refund.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="grid gap-2">
                                    <Label htmlFor="tanggal">Tanggal efektif (hari pertama tidak ikut lagi)</Label>
                                    <Input
                                        id="tanggal"
                                        type="date"
                                        name="tanggal"
                                        defaultValue={les ? saranTanggal.les : saranTanggal.ekskul}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {les
                                            ? 'Disarankan tanggal 1 bulan depan (berhenti di akhir bulan). Bulan yang sudah diikuti walau sekali tetap ditagih dan tidak direfund.'
                                            : 'Tanpa peringatan bila di pergantian semester (1 Januari) atau sampai akhir bulan pertama semester (Agustus/Januari).'}
                                    </p>
                                    <InputError message={errors.tanggal} />
                                </div>

                                <KotakKonfirmasi pesan={errors.konfirmasi} name="konfirmasi" label="Ya, catat sebagai pengecualian" />

                                <div className="grid gap-2">
                                    <Label htmlFor="alasan_berhenti">
                                        Alasan {les ? '(opsional)' : '(wajib bila di luar batas semester)'}
                                    </Label>
                                    <Input id="alasan_berhenti" name="alasan_berhenti" placeholder="mis. sakit, permintaan orang tua" />
                                    <InputError message={errors.alasan_berhenti ?? errors.pendaftaran} />
                                </div>

                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={onClose}>
                                        Batal
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Berhentikan
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

// FR-11: pindah ekskul ke ekskul lain. Uang yang sudah dibayar menjadi potongan tagihan kegiatan baru (#29)
export function DialogPindah({
    pendaftaran,
    kegiatan,
    saranTanggal,
    onClose,
}: {
    pendaftaran: PendaftaranDiubah | null;
    kegiatan: EkskulTujuan[];
    saranTanggal: SaranTanggal;
    onClose: () => void;
}) {
    return (
        <Dialog open={pendaftaran !== null} onOpenChange={(buka) => !buka && onClose()}>
            <DialogContent>
                {pendaftaran && (
                    <IsiPindah
                        key={pendaftaran.id}
                        pendaftaran={pendaftaran}
                        kegiatan={kegiatan.filter((k) => k.id !== pendaftaran.induk_id && sesuaiJenjang(k, pendaftaran.jenjang))}
                        saranTanggal={saranTanggal}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function IsiPindah({
    pendaftaran,
    kegiatan,
    saranTanggal,
    onClose,
}: {
    pendaftaran: PendaftaranDiubah;
    kegiatan: EkskulTujuan[];
    saranTanggal: SaranTanggal;
    onClose: () => void;
}) {
    const [tujuanId, setTujuanId] = useState('');
    const tujuan = kegiatan.find((k) => String(k.id) === tujuanId);

    return (
        <Form
            {...PendaftaranController.pindah.form(pendaftaran.id)}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Pindah dari {pendaftaran.kegiatan}</DialogTitle>
                        <DialogDescription>
                            {pendaftaran.siswa.nama} ({pendaftaran.kelas}) · biaya sekarang {rupiah(pendaftaran.biaya)}/bulan.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="ekstrakurikuler_id">Pindah ke</Label>
                        <select
                            id="ekstrakurikuler_id"
                            name="ekstrakurikuler_id"
                            value={tujuanId}
                            onChange={(e) => setTujuanId(e.target.value)}
                            className={kelasPilihan}
                        >
                            <option value="">Pilih ekskul tujuan</option>
                            {kegiatan.map((k) => (
                                <option key={k.id} value={k.id}>
                                    {k.nama} ({k.biaya > 0 ? rupiah(k.biaya) : 'gratis'})
                                </option>
                            ))}
                        </select>
                        {tujuan && (
                            <p className="text-xs text-muted-foreground">
                                {tujuan.biaya === pendaftaran.biaya
                                    ? 'Biaya sama: kegiatan baru berlaku mulai tanggal pindah, dan pembayaran bulan itu serta bulan berikutnya dialihkan utuh.'
                                    : 'Biaya berbeda: kegiatan lama berjalan sampai akhir bulan, kegiatan baru mulai bulan berikutnya. Pembayaran di muka menjadi potongan tagihan kegiatan baru; sisa yang melewati akhir semester direfund.'}
                            </p>
                        )}
                        <InputError message={errors.ekstrakurikuler_id ?? errors.kegiatan} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal">Tanggal pindah</Label>
                        <Input id="tanggal" type="date" name="tanggal" defaultValue={saranTanggal.ekskul} />
                        <InputError message={errors.tanggal} />
                    </div>

                    <KotakKonfirmasi pesan={errors.konfirmasi} name="konfirmasi" label="Ya, catat sebagai pengecualian" />
                    <KotakKonfirmasi pesan={errors.kuota} name="abaikan_kuota" label="Tetap pindahkan (kuota penuh diatur TU secara manual)" />

                    <div className="grid gap-2">
                        <Label htmlFor="alasan_berhenti">Alasan (wajib bila di luar batas semester)</Label>
                        <Input id="alasan_berhenti" name="alasan_berhenti" placeholder="mis. permintaan orang tua" />
                        <InputError message={errors.alasan_berhenti ?? errors.pendaftaran} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing || tujuanId === ''}>
                            Pindahkan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

// Hanya ekskul untuk jenjang siswa yang ditawarkan; server tetap memeriksa ulang (PendaftaranService)
function sesuaiJenjang(k: EkskulTujuan, jenjang: number | null): boolean {
    return k.jenjang_min === null || jenjang === null || (jenjang >= k.jenjang_min && jenjang <= (k.jenjang_max ?? 6));
}

// Peringatan dari server (batas semester / kuota penuh) beserta checkbox konfirmasinya. Tetap tampil setelah pertama
// muncul, supaya centangannya ikut terkirim walau kiriman berikutnya gagal karena hal lain (mis. alasan kosong)
function KotakKonfirmasi({ pesan, name, label }: { pesan?: string; name: string; label: string }) {
    const [terakhir, setTerakhir] = useState<string | null>(null);

    if (pesan && pesan !== terakhir) {
        setTerakhir(pesan);
    }

    if (!terakhir) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            <span>{terakhir}</span>
            <label className="flex items-center gap-2">
                <input type="checkbox" name={name} value="1" className="size-4 accent-primary" />
                {label}
            </label>
        </div>
    );
}
