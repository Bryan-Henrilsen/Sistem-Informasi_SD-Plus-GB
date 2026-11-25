import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import jurnalGuru from '@/routes/jurnal-guru';

type Jadwal = {
    id: number;
    jam_ke: number | null;
    slot: string;
    jam_mulai: string;
    jam_selesai: string;
    jenis: 'utama' | 'damping' | 'piket' | null;
    jenis_nama: string | null;
    kelas: string | null;
    mapel: string | null;
    guru_utama: string | null; // untuk damping: guru utama yang didampingi
};

type Props = {
    tahun_ajaran: string | null;
    hari: { hari: number; nama: string; jadwal: Jadwal[] }[];
    ekskul: { id: number; nama: string; peran: string; jadwal: { hari: string; jam: string; jenjang: string | null; lokasi: string }[] }[];
    les: { kelas: string }[];
};

const warnaJenis: Record<string, string> = {
    utama: 'border-l-primary',
    damping: 'border-l-sky-500',
    piket: 'border-l-amber-500',
};

// Jadwal mengajar pribadi guru (PRD B.2 FR-1, #51): per hari dari Jadwal Guru, ditambah ekskul dan les yang dipegang. Read-only
export default function JadwalSaya({ tahun_ajaran, hari, ekskul, les }: Props) {
    return (
        <>
            <Head title="Jadwal Saya" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Jadwal Saya"
                    description={`Jadwal mengajar Anda${tahun_ajaran ? ` tahun ajaran ${tahun_ajaran}` : ''}. Jadwal dapat berubah selama disusun Waka Kurikulum.`}
                />

                {hari.length === 0 ? (
                    <p className="rounded-md border p-6 text-center text-sm text-muted-foreground">
                        Belum ada jadwal mengajar untuk Anda. Jadwal muncul di sini setelah Waka Kurikulum menyusunnya.
                    </p>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {hari.map((h) => (
                            <Card key={h.hari} className="gap-3 py-4">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base">{h.nama}</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2 px-4">
                                    {h.jadwal.map((j) => (
                                        <div key={j.id} className={`rounded-md border border-l-4 p-2 text-sm ${warnaJenis[j.jenis ?? ''] ?? ''}`}>
                                            <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {j.jam_ke ? `Jam ke-${j.jam_ke}` : j.slot} · {j.jam_mulai}–{j.jam_selesai}
                                                </span>
                                                <Badge variant="outline">{j.jenis_nama ?? j.jenis}</Badge>
                                            </div>
                                            <p className="font-medium">
                                                {j.jenis === 'piket' ? 'Piket' : [j.mapel, j.kelas].filter(Boolean).join(' · ')}
                                            </p>
                                            {j.jenis === 'damping' && j.guru_utama && <p className="text-xs text-muted-foreground">Mendampingi {j.guru_utama}</p>}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                {(ekskul.length > 0 || les.length > 0) && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {ekskul.length > 0 && (
                            <Card className="gap-3 py-4">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base">Ekstrakurikuler</CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-2 px-4 text-sm">
                                    {ekskul.map((e) => (
                                        <div key={e.id}>
                                            <p className="font-medium">
                                                {e.nama} <span className="font-normal text-muted-foreground">· {e.peran}</span>
                                            </p>
                                            {e.jadwal.map((j, i) => (
                                                <p key={i} className="text-xs text-muted-foreground">
                                                    {j.hari} {j.jam}
                                                    {j.jenjang && ` (${j.jenjang})`} · {j.lokasi}
                                                </p>
                                            ))}
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                        {les.length > 0 && (
                            <Card className="gap-3 py-4">
                                <CardHeader className="px-4">
                                    <CardTitle className="text-base">Les tambahan</CardTitle>
                                </CardHeader>
                                <CardContent className="px-4 text-sm">
                                    {les.map((l) => (
                                        <p key={l.kelas}>Les Kelas {l.kelas} · sebagai wali kelas</p>
                                    ))}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

JadwalSaya.layout = {
    breadcrumbs: [{ title: 'Jadwal Saya', href: jurnalGuru.jadwalSaya() }],
};
