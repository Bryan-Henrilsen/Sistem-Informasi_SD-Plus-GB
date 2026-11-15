import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type OpsiPilih = { id: number; label: string; keterangan?: string };

// Dialog memilih beberapa orang sekaligus (pengajar, pendamping); hasilnya dikirim sebagai daftar id
export function DialogPilihBanyak({
    buka,
    judul,
    deskripsi,
    opsi,
    terpilih,
    url,
    field,
    onClose,
}: {
    buka: boolean;
    judul: string;
    deskripsi?: string;
    opsi: OpsiPilih[];
    terpilih: number[];
    url: string;
    field: string;
    onClose: () => void;
}) {
    return (
        <Dialog open={buka} onOpenChange={(b) => !b && onClose()}>
            <DialogContent>
                {buka && (
                    <IsiPilih judul={judul} deskripsi={deskripsi} opsi={opsi} terpilih={terpilih} url={url} field={field} onClose={onClose} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function IsiPilih({
    judul,
    deskripsi,
    opsi,
    terpilih,
    url,
    field,
    onClose,
}: {
    judul: string;
    deskripsi?: string;
    opsi: OpsiPilih[];
    terpilih: number[];
    url: string;
    field: string;
    onClose: () => void;
}) {
    const [pilihan, setPilihan] = useState<number[]>(terpilih);
    const [cari, setCari] = useState('');
    const [proses, setProses] = useState(false);
    const tampil = opsi.filter((o) => o.label.toLowerCase().includes(cari.toLowerCase()));

    function ubah(id: number, dipilih: boolean) {
        setPilihan((lama) => (dipilih ? [...lama, id] : lama.filter((x) => x !== id)));
    }

    function simpan() {
        router.post(url, { [field]: pilihan }, {
            preserveScroll: true,
            onStart: () => setProses(true),
            onFinish: () => setProses(false),
            onSuccess: onClose,
        });
    }

    return (
        <div className="flex flex-col gap-4">
            <DialogHeader>
                <DialogTitle>{judul}</DialogTitle>
                {deskripsi && <DialogDescription>{deskripsi}</DialogDescription>}
            </DialogHeader>

            <Input placeholder="Cari nama" value={cari} onChange={(e) => setCari(e.target.value)} />

            <ul className="max-h-72 divide-y overflow-y-auto rounded-md border">
                {tampil.map((o) => (
                    <li key={o.id}>
                        <label className="flex cursor-pointer items-center gap-3 p-2 text-sm hover:bg-muted/50">
                            <Checkbox checked={pilihan.includes(o.id)} onCheckedChange={(v) => ubah(o.id, v === true)} />
                            <span className="flex-1">{o.label}</span>
                            {o.keterangan && <span className="text-xs text-muted-foreground">{o.keterangan}</span>}
                        </label>
                    </li>
                ))}
                {tampil.length === 0 && <li className="p-3 text-sm text-muted-foreground">Tidak ada yang cocok.</li>}
            </ul>

            <DialogFooter>
                <span className="mr-auto self-center text-xs text-muted-foreground">{pilihan.length} dipilih</span>
                <Button type="button" variant="ghost" onClick={onClose}>
                    Batal
                </Button>
                <Button type="button" onClick={simpan} disabled={proses}>
                    Simpan
                </Button>
            </DialogFooter>
        </div>
    );
}
