import { Form, Head, usePage } from "@inertiajs/react";
import PeriodeLaporanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PeriodeLaporanController';
import Heading from "@/components/heading";
import InputError from "@/components/input-error";
import { LabelStatus } from "@/components/les-ekskul/label-status";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle
} from '@/components/ui/card';
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { namaBulan, rupiah, tanggal } from "@/lib/format";
import { index } from '@/routes/les-ekskul/periode';
import type { Auth } from "@/types";

type Periode = {
    id: number;
    bulan: string;
    tanggal_mulai: string;
    tanggal_selesai: string;
    status: string;
    tagihan: {
        jumlah: number;
        lunas: number;
        harus_dibayar: number;
        terbayar: number;
    };
    uang_masuk: number;
    jumlah_transaksi: number;
};

type Props = {
    periode: Periode[];
    usulan: {
        bulan: string;
        tanggal_mulai: string; tanggal_selesai: string;
    };
};

export default function PeriodeIndex({ periode, usulan }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const bisaMembuka = auth.roles.some((r) => ['tu', 'bendahara'].includes(r));

    return (
        <>
            <Head title="Periode Laporan" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Periode Laporan"
                    description="Periode pembukuan bulanan. Membuka periode baru otomatis membuat tagihan bulan itu untuk semua siswa yang masih ikut."
                />

            {bisaMembuka && (
                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>
                            Buka periode {namaBulan(usulan.bulan)}
                        </CardTitle>
                        <CardDescription>
                            Mulai {tanggal(usulan.tanggal_mulai)} (sehari setelah periode sebelumnya). Pilih tanggal selesainya
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...PeriodeLaporanController.store.form()}
                            options={{preserveScroll: true}}
                            className="flex items-end gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="tanggal_selesai">
                                            Tanggal selesai
                                        </Label>
                                        <Input
                                            id="tanggal_selesai"
                                            type="date"
                                            name="tanggal_selesai"
                                            defaultValue={
                                                usulan.tanggal_selesai
                                            }
                                            required
                                        />
                                        <InputError message={errors.tanggal_selesai} />
                                    </div>
                                    <Button disabled={processing} >
                                        Buka periode
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            )}

            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3">Periode</th>
                            <th className="p-3">Rentang tanggal</th>
                            <th className="p-3">Status</th>
                            <th className="p-3">Tagihan bulan ini</th>
                            <th className="p-3 text-right">Uang masuk di periode</th>
                        </tr>
                    </thead>
                    <tbody>
                        {periode.map((p) => (
                            <tr key={p.id} className="border-t">
                                <td className="p-3 font-medium">
                                    {namaBulan(p.bulan)}
                                </td>
                                <td className="p-3">
                                    {tanggal(p.tanggal_mulai)} -{' '}
                                    {tanggal(p.tanggal_selesai)}
                                </td>
                                <td className="p-3">
                                    <LabelStatus status={p.status} />
                                </td>
                                <td className="p-3">
                                    {p.tagihan.jumlah === 0 ? (
                                        <span className="text-muted-foreground">
                                            Tidak ada tagihan
                                        </span>
                                    ) : (
                                        <>
                                            {p.tagihan.lunas}/
                                            {p.tagihan.jumlah} lunas &middot;{' '}
                                            {rupiah(p.tagihan.terbayar)}{' '}
                                            dari{' '}
                                            {rupiah(p.tagihan.harus_dibayar)}
                                        </>
                                    )}
                                </td>
                                <td className="p-3 text-right">
                                    {rupiah(p.uang_masuk)}
                                    <div className="text-xs text-muted-foreground">
                                        {p.jumlah_transaksi} transaksi
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {periode.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="p-6 text-center text-muted-foreground"
                                >
                                    Belum ada periode laporan.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            </div>
        </>
    );
}

PeriodeIndex.layout = {
    breadcrumbs: [{ title: 'Periode Laporan', href: index()}],
};
