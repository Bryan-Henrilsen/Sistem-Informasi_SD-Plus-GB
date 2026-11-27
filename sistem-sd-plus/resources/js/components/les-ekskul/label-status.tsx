import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

// Badge pil status sesuai referensi desain sekolah (PRD #56): hijau = selesai, biru = info, kuning = menunggu, merah = bermasalah;
// status lain (draft, ditutup, …) memakai warna netral

const warna: Record<string, string> = {
    lunas: 'border-sekolah-sukses/25 bg-sekolah-sukses/10 text-sekolah-sukses',
    terverifikasi: 'border-sekolah-sukses/25 bg-sekolah-sukses/10 text-sekolah-sukses',
    disetujui: 'border-sekolah-sukses/25 bg-sekolah-sukses/10 text-sekolah-sukses',
    aktif: 'border-sekolah-sukses/25 bg-sekolah-sukses/10 text-sekolah-sukses',
    pindah: 'border-sekolah-info/25 bg-sekolah-info/10 text-sekolah-info',
    terbuka: 'border-sekolah-info/25 bg-sekolah-info/10 text-sekolah-info',
    diajukan: 'border-sekolah-tertunda/25 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    pending: 'border-sekolah-tertunda/25 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    belum_dicek: 'border-sekolah-tertunda/25 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    sebagian: 'border-sekolah-tertunda/25 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    belum: 'border-sekolah-bahaya/25 bg-sekolah-bahaya/10 text-sekolah-bahaya',
    ditolak: 'border-sekolah-bahaya/25 bg-sekolah-bahaya/10 text-sekolah-bahaya',
    berhenti: 'border-sekolah-bahaya/25 bg-sekolah-bahaya/10 text-sekolah-bahaya',
};

const teks: Record<string, string> = {
    belum_dicek: 'belum dicek',
    terverifikasi: 'terverifikasi TU',
};

export function LabelStatus({ status }: { status: string }) {
    return (
        <Badge variant="outline" className={cn('rounded-full capitalize', warna[status] ?? 'border-sekolah-netral/25 bg-sekolah-netral/10 text-sekolah-netral')}>{teks[status] ?? status}</Badge>
    )
}
