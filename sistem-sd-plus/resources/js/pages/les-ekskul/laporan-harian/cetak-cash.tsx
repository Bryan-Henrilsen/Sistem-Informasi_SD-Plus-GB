import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { LabelStatus } from '@/components/les-ekskul/label-status';
import { Button } from '@/components/ui/button';
import { hariTanggal, namaBulan, rupiah, tanggal } from '@/lib/format';
import { index } from '@/routes/les-ekskul/laporan-harian';

type TransaksiCash = {
    id: number;
    siswa: string;
    kelas: string | null;
    tanggal: string;
    terlambat: boolean; // uang masuk sebelum tanggal laporan (dicatat susulan)
    nominal: number;
    alokasi: { kegiatan: string; bulan: string[] }[];
};

type Props = {
    laporan: { id: number; tanggal: string; status: string };
    transaksi: TransaksiCash[];
    total: number;
};

// Lembar A4 transaksi cash satu hari: diserahkan TU ke Bendahara bersama uangnya sebagai arsip fisik (#49).
// Tombol dan sidebar disembunyikan saat dicetak (print:hidden + CSS @media print di app.css)
export default function CetakCash({ laporan, transaksi, total }: Props) {
    return (
        <>
            <Head title={`Cetak Cash ${tanggal(laporan.tanggal)}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 print:max-w-none print:bg-white print:p-0 print:text-black">
                <div className="flex flex-wrap items-center justify-between gap-3 print:hidden">
                    <Button variant="ghost" asChild>
                        <Link href={index()}>
                            <ArrowLeft /> Kembali ke Laporan Harian
                        </Link>
                    </Button>
                    <div className="flex items-center gap-3">
                        <span className="flex items-center gap-2 text-sm text-muted-foreground">
                            Status laporan <LabelStatus status={laporan.status} />
                        </span>
                        <Button onClick={() => window.print()} disabled={transaksi.length === 0}>
                            <Printer /> Cetak
                        </Button>
                    </div>
                </div>

                <div className="text-center">
                    <h1 className="text-lg font-semibold">LAPORAN HARIAN UANG CASH</h1>
                    <p className="text-sm">Les & Ekstrakurikuler SD Plus Gembala Baik</p>
                    <p className="text-sm">{hariTanggal(laporan.tanggal)}</p>
                </div>

                {transaksi.length === 0 ? (
                    <p className="rounded-md border p-4 text-center text-sm text-muted-foreground">
                        Tidak ada transaksi cash pada laporan ini.
                    </p>
                ) : (
                    <table className="w-full border-collapse text-sm print:text-xs">
                        <thead>
                            <tr className="border-y-2 border-foreground text-left print:border-black">
                                <th className="w-10 p-2">No</th>
                                <th className="p-2">Siswa</th>
                                <th className="p-2">Kelas</th>
                                <th className="p-2">Kegiatan & bulan</th>
                                <th className="p-2 text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transaksi.map((t, i) => (
                                <tr key={t.id} className="border-b align-top print:break-inside-avoid print:border-gray-400">
                                    <td className="p-2">{i + 1}</td>
                                    <td className="p-2">
                                        {t.siswa}
                                        {t.terlambat && (
                                            <div className="text-xs text-muted-foreground print:text-black">
                                                uang diterima {tanggal(t.tanggal)}
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-2 whitespace-nowrap">{t.kelas ?? '–'}</td>
                                    <td className="p-2">
                                        {t.alokasi.length === 0
                                            ? '–'
                                            : t.alokasi.map((a) => (
                                                  <div key={a.kegiatan}>
                                                      {a.kegiatan} — {a.bulan.map((b) => namaBulan(b)).join(', ')}
                                                  </div>
                                              ))}
                                    </td>
                                    <td className="p-2 text-right tabular-nums">{rupiah(t.nominal)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-y-2 border-foreground font-semibold print:border-black">
                                <td className="p-2" colSpan={4}>
                                    Total ({transaksi.length} transaksi)
                                </td>
                                <td className="p-2 text-right tabular-nums">{rupiah(total)}</td>
                            </tr>
                        </tfoot>
                    </table>
                )}

                <div className="grid grid-cols-2 gap-8 pt-6 text-center text-sm print:break-inside-avoid">
                    <TandaTangan peran="Diserahkan oleh TU" />
                    <TandaTangan peran="Diterima oleh Bendahara" />
                </div>
            </div>
        </>
    );
}

function TandaTangan({ peran }: { peran: string }) {
    return (
        <div>
            <p>{peran}</p>
            <p className="text-muted-foreground print:text-black">Tanggal: ....................</p>
            <div className="h-20" />
            <p>(..............................)</p>
        </div>
    );
}

CetakCash.layout = {
    breadcrumbs: [
        { title: 'Laporan Harian', href: index() },
        { title: 'Cetak cash', href: '#' },
    ],
};
