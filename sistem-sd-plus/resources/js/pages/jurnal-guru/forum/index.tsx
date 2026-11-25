import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ForumController from '@/actions/App/Domains/JurnalGuru/Http/Controllers/ForumController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { waktu } from '@/lib/format';
import forum from '@/routes/jurnal-guru/forum';

type Thread = {
    guru_id: number;
    nama: string;
    aktif: boolean;
    jumlah_pesan: number;
    belum_dibaca: number;
    terakhir: string | null;
    cuplikan: string | null;
    cuplikan_dari_guru: boolean;
};

// Daftar percakapan untuk Kepala Sekolah (PRD B.2 FR-3, #51): yang belum dibaca di atas, lalu yang terbaru
export default function ForumIndex({ thread }: { thread: Thread[] }) {
    const [cari, setCari] = useState('');
    const tampil = thread.filter((t) => t.nama.toLowerCase().includes(cari.trim().toLowerCase()));
    const belumDibaca = thread.reduce((n, t) => n + t.belum_dibaca, 0);

    return (
        <>
            <Head title="Forum Guru" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
                <Heading
                    title="Forum Guru"
                    description={`Percakapan privat dengan setiap guru. ${belumDibaca > 0 ? `${belumDibaca} pesan belum dibaca.` : 'Semua pesan sudah dibaca.'} Anda juga bisa memulai percakapan lebih dulu.`}
                />

                <Input value={cari} onChange={(e) => setCari(e.target.value)} placeholder="Cari nama guru" className="sm:max-w-xs" />

                <div className="divide-y rounded-xl border">
                    {tampil.map((t) => (
                        <Link
                            key={t.guru_id}
                            href={ForumController.show.url(t.guru_id)}
                            className="flex items-start justify-between gap-3 p-3 transition hover:bg-muted/50"
                        >
                            <div className="min-w-0">
                                <p className="flex items-center gap-2 font-medium">
                                    {t.nama}
                                    {!t.aktif && <Badge variant="outline">tidak aktif</Badge>}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    {t.cuplikan ? `${t.cuplikan_dari_guru ? '' : 'Anda: '}${t.cuplikan}` : 'Belum ada pesan'}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1 text-xs text-muted-foreground">
                                {t.terakhir && <span>{waktu(t.terakhir)}</span>}
                                {t.belum_dibaca > 0 && <Badge>{t.belum_dibaca} baru</Badge>}
                            </div>
                        </Link>
                    ))}
                    {tampil.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">Tidak ada guru yang cocok.</p>}
                </div>
            </div>
        </>
    );
}

ForumIndex.layout = {
    breadcrumbs: [{ title: 'Forum Guru', href: forum.index() }],
};
