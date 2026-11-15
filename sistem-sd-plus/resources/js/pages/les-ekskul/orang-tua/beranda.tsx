import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, UserPlus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import ortu from '@/routes/ortu';
import type { Auth } from '@/types';

export type AnakRingkas = { id: number; nama: string; kelas: string | null; kegiatan: string[] };

// Beranda orang tua: sapaan dan ringkasan setiap anak (Ringkasan v1.11 #36)
export default function BerandaOrangTua({ anak }: { anak: AnakRingkas[] }) {
    const { auth } = usePage<{ auth: Auth }>().props;

    return (
        <>
            <Head title="Beranda" />

            <div className="flex flex-col gap-4">
                <div>
                    <h1 className="text-lg font-semibold">Halo, {auth.user?.name}</h1>
                    <p className="text-sm text-muted-foreground">Pantau les dan ekstrakurikuler anak Anda di sini.</p>
                </div>

                {anak.length === 0 ? (
                    <Card className="py-5">
                        <CardContent className="flex flex-col items-start gap-3 px-5">
                            <p className="font-medium">Belum ada anak di akun Anda</p>
                            <p className="text-sm text-muted-foreground">
                                Pilih kelas lalu nama anak Anda. Setelah itu Anda bisa melihat kegiatan dan tagihannya.
                            </p>
                            <Button asChild>
                                <Link href={ortu.anak()}>
                                    <UserPlus className="size-4" />
                                    Tambahkan anak
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    anak.map((a) => <KartuAnak key={a.id} anak={a} />)
                )}
            </div>
        </>
    );
}

export function KartuAnak({ anak, aksi }: { anak: AnakRingkas; aksi?: React.ReactNode }) {
    return (
        <Card className="py-4">
            <CardContent className="flex flex-col gap-2 px-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="truncate font-medium">{anak.nama}</p>
                        <p className="text-sm text-muted-foreground">{anak.kelas ? `Kelas ${anak.kelas}` : 'Belum terdaftar di kelas tahun ini'}</p>
                    </div>
                    {aksi ?? <ChevronRight className="mt-1 size-4 shrink-0 text-muted-foreground" />}
                </div>
                {anak.kegiatan.length > 0 ? (
                    <div className="flex flex-wrap gap-1.5">
                        {anak.kegiatan.map((k) => (
                            <span key={k} className="rounded-full bg-muted px-2 py-0.5 text-xs">
                                {k}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">Belum mengikuti les atau ekstrakurikuler.</p>
                )}
            </CardContent>
        </Card>
    );
}
