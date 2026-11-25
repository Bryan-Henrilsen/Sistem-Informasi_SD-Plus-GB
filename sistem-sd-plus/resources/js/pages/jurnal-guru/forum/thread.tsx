import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCheck, Send, Trash2 } from 'lucide-react';
import { useEffect, useRef } from 'react';
import ForumController from '@/actions/App/Domains/JurnalGuru/Http/Controllers/ForumController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { waktu } from '@/lib/format';
import { cn } from '@/lib/utils';
import forum from '@/routes/jurnal-guru/forum';

type Pesan = {
    id: number;
    isi: string;
    dari_guru: boolean;
    milik_saya: boolean;
    pengirim: string;
    created_at: string;
    dibaca_at: string | null;
    bisa_dihapus: boolean; // pengirimnya sendiri, belum lewat batas menit
};

type Props = {
    guru: { id: number; nama: string; aktif: boolean };
    pesan: Pesan[];
    sebagaiKepsek: boolean; // Kepala Sekolah membuka percakapan seorang guru
    panjangMaks: number;
    menitBolehHapus: number;
};

// Satu percakapan berkelanjutan guru ↔ Kepala Sekolah (PRD B.2 FR-2–FR-4, #51). Pesan sendiri di kanan, lawan bicara di kiri.
// Tanpa chat real-time: pesan baru terlihat setelah halaman dibuka/dimuat ulang
export default function ForumThread({ guru, pesan, sebagaiKepsek, panjangMaks, menitBolehHapus }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const form = useForm({ isi: '' });
    const bawah = useRef<HTMLDivElement>(null);

    // Selalu mulai dari pesan terbaru
    useEffect(() => {
        bawah.current?.scrollIntoView({ block: 'end' });
    }, [pesan.length]);

    function kirim(e?: React.FormEvent) {
        e?.preventDefault();
        form.post(ForumController.store.url(guru.id), { preserveScroll: true, onSuccess: () => form.reset('isi') });
    }

    function hapus(p: Pesan) {
        if (confirm('Hapus pesan ini? Pesan yang sudah dihapus tidak bisa dikembalikan.')) {
            router.delete(ForumController.destroy.url(p.id), { preserveScroll: true });
        }
    }

    const lawan = sebagaiKepsek ? guru.nama : 'Kepala Sekolah';

    return (
        <>
            <Head title={sebagaiKepsek ? `Forum ${guru.nama}` : 'Forum Guru'} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={sebagaiKepsek ? guru.nama : 'Forum dengan Kepala Sekolah'}
                        description={
                            sebagaiKepsek
                                ? 'Percakapan privat dengan guru ini. Hanya Anda dan guru ini yang bisa membacanya.'
                                : 'Sampaikan pesan, pertanyaan, atau keluhan. Hanya Anda dan Kepala Sekolah yang bisa membacanya.'
                        }
                    />
                    {sebagaiKepsek && (
                        <Button variant="ghost" asChild>
                            <Link href={forum.index()}>
                                <ArrowLeft /> Semua guru
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex min-h-64 flex-col gap-3 rounded-xl border p-4">
                    {pesan.length === 0 && (
                        <p className="m-auto text-center text-sm text-muted-foreground">
                            Belum ada pesan. {sebagaiKepsek ? `Tulis pesan pertama untuk ${guru.nama}.` : 'Tulis pesan pertama Anda di bawah.'}
                        </p>
                    )}
                    {pesan.map((p) => (
                        <div key={p.id} className={cn('flex flex-col gap-1', p.milik_saya ? 'items-end' : 'items-start')}>
                            <div
                                className={cn(
                                    'max-w-[85%] rounded-2xl px-4 py-2 text-sm whitespace-pre-wrap break-words',
                                    p.milik_saya ? 'rounded-br-sm bg-primary text-primary-foreground' : 'rounded-bl-sm bg-muted',
                                )}
                            >
                                {p.isi}
                            </div>
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                {!p.milik_saya && <span className="font-medium">{p.pengirim}</span>}
                                <span>{waktu(p.created_at)}</span>
                                {p.milik_saya &&
                                    (p.dibaca_at ? (
                                        <span className="flex items-center gap-0.5" title={`Dibaca ${waktu(p.dibaca_at)}`}>
                                            <CheckCheck className="size-3.5" /> dibaca
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-0.5">
                                            <Check className="size-3.5" /> terkirim
                                        </span>
                                    ))}
                                {p.bisa_dihapus && (
                                    <button type="button" onClick={() => hapus(p)} className="flex items-center gap-0.5 hover:text-destructive" title={`Bisa dihapus dalam ${menitBolehHapus} menit setelah dikirim`}>
                                        <Trash2 className="size-3.5" /> hapus
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                    <div ref={bawah} />
                </div>

                <InputError message={errors.pesan} />

                {guru.aktif ? (
                    <form onSubmit={kirim} className="flex flex-col gap-2">
                        <textarea
                            value={form.data.isi}
                            onChange={(e) => form.setData('isi', e.target.value)}
                            // Ctrl+Enter mengirim; Enter biasa membuat baris baru
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                    kirim();
                                }
                            }}
                            rows={4}
                            maxLength={panjangMaks}
                            placeholder={`Tulis pesan untuk ${lawan}…`}
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <InputError message={form.errors.isi} />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs text-muted-foreground">
                                {form.data.isi.length}/{panjangMaks} karakter · Ctrl+Enter untuk mengirim · pesan bisa dihapus dalam {menitBolehHapus} menit,
                                sesudah itu menjadi arsip
                            </span>
                            <Button type="submit" disabled={form.processing || form.data.isi.trim() === ''}>
                                <Send /> Kirim
                            </Button>
                        </div>
                    </form>
                ) : (
                    <p className="rounded-md border p-3 text-sm text-muted-foreground">Guru ini sudah tidak aktif; percakapannya hanya bisa dibaca.</p>
                )}
            </div>
        </>
    );
}

ForumThread.layout = {
    breadcrumbs: [{ title: 'Forum Guru', href: forum.index() }],
};
