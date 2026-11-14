import { Head, Link, usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { namaBulan, rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as laporanBulanan } from '@/routes/les-ekskul/periode';
import type { Auth } from '@/types';

type KartuTugas = {
    judul: string;
    jumlah: number;
    keterangan: string;
    href: string;
    nada: 'tugas' | 'info'; // tugas = perlu tindakan Anda; info = menunggu orang lain
};

type Props = {
    tugas: KartuTugas[];
    keuangan: {
        hari_ini: number;
        hari_ini_jumlah: number;
        periode: { id: number; bulan: string; total: number } | null;
        tagihan_bulan_ini: { lunas: number; total: number };
        tunggakan: number;
    } | null;
};

// Beranda "Tugas Saya": begitu login, pengguna melihat apa yang menunggu dirinya dan bisa langsung membukanya
export default function Dashboard({ tugas, keuangan }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const adaTugas = tugas.some((k) => k.nada === 'tugas' && k.jumlah > 0);

    return (
        <>
            <Head title="Beranda" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={`Halo, ${auth.user.name}`}
                    description={
                        tugas.length === 0
                            ? 'Selamat datang di Sistem Informasi SD Plus Gembala Baik.'
                            : adaTugas
                              ? 'Berikut pekerjaan yang menunggu Anda. Klik kartu untuk langsung membukanya.'
                              : 'Semua pekerjaan Anda sudah beres.'
                    }
                />

                {tugas.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {tugas.map((k) => (
                            <KartuTugasItem key={k.judul} kartu={k} />
                        ))}
                    </div>
                )}

                {keuangan && (
                    <div className="flex flex-col gap-3">
                        <h3 className="font-medium">Keuangan Les & Ekskul</h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Angka judul="Uang masuk hari ini" isi={rupiah(keuangan.hari_ini)} keterangan={`${keuangan.hari_ini_jumlah} transaksi`} />
                            <Angka
                                judul={keuangan.periode ? `Periode ${namaBulan(keuangan.periode.bulan)}` : 'Periode berjalan'}
                                isi={keuangan.periode ? rupiah(keuangan.periode.total) : '–'}
                                keterangan={keuangan.periode ? 'Uang masuk sejauh ini' : 'Belum ada periode yang mencakup hari ini'}
                                href={keuangan.periode ? laporanBulanan(keuangan.periode.id).url : undefined}
                            />
                            <Angka
                                judul="Tagihan bulan ini lunas"
                                isi={`${keuangan.tagihan_bulan_ini.lunas} / ${keuangan.tagihan_bulan_ini.total}`}
                            />
                            <Angka judul="Total tunggakan" isi={rupiah(keuangan.tunggakan)} keterangan="Tagihan bulan lalu yang belum lunas" />
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function KartuTugasItem({ kartu }: { kartu: KartuTugas }) {
    const beres = kartu.jumlah === 0;

    return (
        <Link
            href={kartu.href}
            className={cn(
                'flex flex-col gap-2 rounded-xl border p-4 transition-colors hover:bg-muted/50',
                !beres && kartu.nada === 'tugas' && 'border-amber-300 bg-amber-50/60 dark:border-amber-800 dark:bg-amber-950/30',
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{kartu.judul}</span>
                {beres ? (
                    <span className="flex items-center gap-1 text-xs text-emerald-600">
                        <CheckCircle2 className="size-4" /> Beres
                    </span>
                ) : (
                    <span
                        className={cn(
                            'text-2xl font-semibold',
                            kartu.nada === 'tugas' ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground',
                        )}
                    >
                        {kartu.jumlah}
                    </span>
                )}
            </div>
            <p className={cn('text-xs', beres ? 'text-muted-foreground/70' : 'text-muted-foreground')}>{kartu.keterangan}</p>
        </Link>
    );
}

function Angka({ judul, isi, keterangan, href }: { judul: string; isi: string; keterangan?: string; href?: string }) {
    const isiKartu = (
        <Card className="h-full gap-2 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-sm font-normal text-muted-foreground">{judul}</CardTitle>
            </CardHeader>
            <CardContent className="px-4">
                <div className="text-2xl font-semibold">{isi}</div>
                {keterangan && <div className="text-xs text-muted-foreground">{keterangan}</div>}
            </CardContent>
        </Card>
    );

    return href ? (
        <Link href={href} className="rounded-xl transition-opacity hover:opacity-80">
            {isiKartu}
        </Link>
    ) : (
        isiKartu
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Beranda', href: dashboard() }],
};
