import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TransaksiController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TransaksiController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { AksiTransfer, DialogTolakTransfer } from '@/components/les-ekskul/aksi-transfer';
import type { TransferRingkas } from '@/components/les-ekskul/aksi-transfer';
import { LabelStatus } from '@/components/les-ekskul/label-status';
import { Paginasi } from '@/components/les-ekskul/paginasi';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { create, index } from '@/routes/les-ekskul/transaksi';
import type { Auth } from '@/types';
import type { MetodeTransaksi, Paginasi as PaginasiData, StatusTransaksi } from '@/types/les-ekskul';

type Transaksi = {
    id: number;
    tanggal: string;
    siswa: string;
    metode: MetodeTransaksi;
    nominal: number;
    status: StatusTransaksi;
    status_approval_bendahara: 'belum_dicek' | 'disetujui' | null;
    sumber_input: 'tu' | 'bendahara' | 'sistem';
    catatan_transfer: string | null;
    alasan_ditolak: string | null;
    diinput_oleh: string | null;
    alokasi: { kegiatan: string; bulan: string; nominal: number }[];
    refund: number;
};

type Props = {
    transaksi: PaginasiData<Transaksi>;
    filter: { nama?: string; status?: string; metode?: string };
    jumlah: { pending: number; menunggu_bendahara: number };
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function TransaksiIndex({ transaksi, filter, jumlah }: Props) {
    const { auth, errors } = usePage<{ auth: Auth; errors: Record<string, string> }>().props;
    const punya = (role: string) => auth.roles.includes(role);
    const [ditolak, setDitolak] = useState<TransferRingkas | null>(null); // transaksi yang sedang dibuka di dialog tolak

    return (
        <>
            <Head title="Transaksi" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Transaksi"
                        description={`${jumlah.pending} transfer menunggu verifikasi TU · ${jumlah.menunggu_bendahara} menunggu persetujuan Bendahara`}
                    />
                    {(punya('tu') || punya('bendahara')) && (
                        <Button asChild>
                            <Link href={create()}>Catat pembayaran</Link>
                        </Button>
                    )}
                </div>

                <Form
                    {...TransaksiController.index.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-center gap-2"
                >
                    <Input name="nama" placeholder="Cari nama siswa" defaultValue={filter.nama} className="w-56" />
                    <select name="status" defaultValue={filter.status ?? ''} className={kelasPilihan}>
                        <option value="">Semua status</option>
                        <option value="pending">Pending (perlu verifikasi TU)</option>
                        <option value="menunggu_bendahara">Menunggu Bendahara</option>
                        <option value="terverifikasi">Terverifikasi</option>
                        <option value="ditolak">Ditolak</option>
                    </select>
                    <select name="metode" defaultValue={filter.metode ?? ''} className={kelasPilihan}>
                        <option value="">Semua metode</option>
                        <option value="cash">Cash</option>
                        <option value="transfer">Transfer</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </Form>

                <InputError message={errors.transaksi} />

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Tanggal</th>
                                <th className="p-3">Siswa & tagihan</th>
                                <th className="p-3">Metode</th>
                                <th className="p-3 text-right">Nominal</th>
                                <th className="p-3">Status</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {transaksi.data.map((t) => (
                                <tr key={t.id} className="border-t align-top">
                                    <td className="p-3 whitespace-nowrap">{tanggal(t.tanggal)}</td>
                                    <td className="p-3">
                                        <div className="font-medium">{t.siswa}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {t.alokasi.length > 0
                                                ? t.alokasi
                                                      .map((a) => `${a.kegiatan} ${namaBulan(a.bulan, true)} (${rupiah(a.nominal)})`)
                                                      .join(', ')
                                                : 'Alokasi dilepas'}
                                            {t.refund > 0 && ` · refund ${rupiah(t.refund)}`}
                                        </div>
                                        {t.alasan_ditolak && (
                                            <div className="text-xs text-rose-600">Ditolak: {t.alasan_ditolak}</div>
                                        )}
                                    </td>
                                    <td className="p-3 capitalize">
                                        {t.metode}
                                        {t.sumber_input === 'bendahara' && (
                                            <div className="text-xs text-muted-foreground normal-case">dari mutasi rekening</div>
                                        )}
                                        {t.catatan_transfer && (
                                            <div className="text-xs text-muted-foreground normal-case">{t.catatan_transfer}</div>
                                        )}
                                    </td>
                                    <td className="p-3 text-right font-medium">{rupiah(t.nominal)}</td>
                                    <td className="p-3">
                                        <div className="flex flex-col items-start gap-1">
                                            <LabelStatus status={t.status} />
                                            {t.status_approval_bendahara && t.status !== 'ditolak' && (
                                                <LabelStatus status={t.status_approval_bendahara} />
                                            )}
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <AksiTransfer t={t} roles={auth.roles} onTolak={setDitolak} />
                                    </td>
                                </tr>
                            ))}
                            {transaksi.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                        Belum ada transaksi yang cocok dengan filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Paginasi data={transaksi} />
            </div>

            <DialogTolakTransfer transaksi={ditolak} onClose={() => setDitolak(null)} />
        </>
    );
}

TransaksiIndex.layout = {
    breadcrumbs: [{ title: 'Transaksi', href: index() }],
};
