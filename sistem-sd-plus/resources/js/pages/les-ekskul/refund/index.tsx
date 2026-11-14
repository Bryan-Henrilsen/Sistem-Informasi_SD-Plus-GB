import { Head, Link, router, usePage } from '@inertiajs/react';
import RefundController from '@/actions/App/Domains/LesEkskul/Http/Controllers/RefundController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Paginasi } from '@/components/les-ekskul/paginasi';
import { Button } from '@/components/ui/button';
import { rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/refund';
import type { Auth } from '@/types';
import type { Paginasi as PaginasiData } from '@/types/les-ekskul';

type Refund = {
    id: number;
    siswa: string;
    kegiatan: string;
    jenis: 'kelebihan_transfer' | 'refund_berhenti';
    nominal: number;
    keterangan: string | null;
    dicatat: string;
    dikembalikan: string | null;
    diproses_oleh: string | null;
};

type Props = {
    refund: PaginasiData<Refund>;
    status: 'proses' | 'berhasil';
    totalBelum: number;
};

const asal: Record<Refund['jenis'], string> = {
    kelebihan_transfer: 'Kelebihan transfer',
    refund_berhenti: 'Berhenti / pindah',
};

export default function RefundIndex({ refund, status, totalBelum }: Props) {
    const { auth, errors } = usePage<{ auth: Auth; errors: Record<string, string> }>().props;
    const bisaMenandai = auth.roles.includes('tu');

    function tandai(r: Refund) {
        if (confirm(`Tandai refund ${rupiah(r.nominal)} untuk ${r.siswa} sudah dikembalikan ke orang tua?`)) {
            router.post(RefundController.tandai.url(r.id), {}, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Refund" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Refund"
                    description={`Uang yang harus dikembalikan ke orang tua: kelebihan transfer yang melewati akhir semester, dan pembayaran di muka saat siswa berhenti atau pindah. Total belum dikembalikan ${rupiah(totalBelum)}.`}
                />

                <div className="flex gap-1 border-b">
                    <TautanTab aktif={status === 'proses'} href={index()}>
                        Belum dikembalikan
                    </TautanTab>
                    <TautanTab aktif={status === 'berhasil'} href={index({ query: { status: 'berhasil' } })}>
                        Sudah dikembalikan
                    </TautanTab>
                </div>

                <InputError message={errors.refund} />

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Dicatat</th>
                                <th className="p-3">Siswa & kegiatan</th>
                                <th className="p-3">Asal</th>
                                <th className="p-3 text-right">Nominal</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {refund.data.map((r) => (
                                <tr key={r.id} className="border-t align-top">
                                    <td className="p-3 whitespace-nowrap">{tanggal(r.dicatat)}</td>
                                    <td className="p-3">
                                        <div className="font-medium">{r.siswa}</div>
                                        <div className="text-xs text-muted-foreground">{r.kegiatan}</div>
                                        {r.keterangan && <div className="text-xs text-muted-foreground">{r.keterangan}</div>}
                                    </td>
                                    <td className="p-3">{asal[r.jenis]}</td>
                                    <td className="p-3 text-right font-medium">{rupiah(r.nominal)}</td>
                                    <td className="p-3">
                                        {status === 'proses' && bisaMenandai && (
                                            <Button size="sm" variant="outline" onClick={() => tandai(r)}>
                                                Tandai sudah dikembalikan
                                            </Button>
                                        )}
                                        {r.dikembalikan && (
                                            <div className="text-xs text-muted-foreground">
                                                {tanggal(r.dikembalikan)}
                                                {r.diproses_oleh && ` · ${r.diproses_oleh}`}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {refund.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        {status === 'proses' ? 'Tidak ada refund yang menunggu.' : 'Belum ada refund yang dikembalikan.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Paginasi data={refund} />
            </div>
        </>
    );
}

function TautanTab({ aktif, href, children }: { aktif: boolean; href: ReturnType<typeof index>; children: React.ReactNode }) {
    return (
        <Link
            href={href}
            className={cn(
                '-mb-px border-b-2 px-3 py-2 text-sm',
                aktif ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </Link>
    );
}

RefundIndex.layout = {
    breadcrumbs: [{ title: 'Refund', href: index() }],
};
