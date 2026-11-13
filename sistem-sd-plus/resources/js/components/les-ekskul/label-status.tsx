import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

// Label berwarna untuk status hijau = selesai, kuning = menunggu, merah = bermasalah

const warna: Record<string, string> = {
    lunas: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    terverifikasi: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    disetujui: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    aktif: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    pindah: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    terbuka: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    belum_dicek: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    sebagian: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    belum: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    ditolak: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    berhenti: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
};

const teks: Record<string, string> = {
    belum_dicek: 'belum dicek',
    terverifikasi: 'terverifikasi TU',
};

export function LabelStatus({ status }: { status: string }) {
    return (
        <Badge variant="secondary" className={cn('capitalize', warna[status])}>{teks[status] ?? status}</Badge>
    )
}
