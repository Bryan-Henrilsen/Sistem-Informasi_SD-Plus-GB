import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import TahunAjaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TahunAjaranController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/tahun-ajaran';

type Siswa = { id: number; nama: string; sudah_di: string | null };
type KelasAsal = { id: number; nama: string; jenjang: number; usulan_tujuan_id: number | null; siswa: Siswa[] };
type KelasTujuan = { id: number; nama: string; jenjang: number };
type Props = {
    tujuan: { id: number; nama: string };
    asal: { id: number; nama: string } | null;
    kelasAsal: KelasAsal[];
    kelasTujuan: KelasTujuan[];
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

// Kenaikan kelas massal per kelas (Ringkasan v1.11 #45): usulan 2A → 3A, kelas 6 lulus, siswa tinggal kelas ditandai per siswa
export default function KenaikanKelas({ tujuan, asal, kelasAsal, kelasTujuan }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [tujuanPerKelas, setTujuanPerKelas] = useState<Record<number, number | ''>>(() =>
        Object.fromEntries(kelasAsal.map((k) => [k.id, k.usulan_tujuan_id ?? ''])),
    );
    // siswa_id → kelas tujuan berjenjang sama (tinggal kelas)
    const [tinggal, setTinggal] = useState<Record<number, number>>({});
    const [dibuka, setDibuka] = useState<number | null>(null);
    const [proses, setProses] = useState(false);

    const belum = kelasAsal.flatMap((k) => k.siswa.filter((s) => !s.sudah_di).map((s) => ({ ...s, kelas: k })));
    const jumlah = {
        tinggal: belum.filter((s) => tinggal[s.id]).length,
        lulus: belum.filter((s) => s.kelas.jenjang === 6 && !tinggal[s.id]).length,
    };
    const naik = belum.length - jumlah.tinggal - jumlah.lulus;
    const kurangTujuan = kelasAsal.filter((k) => k.jenjang < 6 && k.siswa.some((s) => !s.sudah_di) && !tujuanPerKelas[k.id]);

    // Kelas tujuan untuk siswa tinggal kelas: berjenjang sama, utamakan nama kelas yang sama (2A → 2A)
    function kelasTinggal(k: KelasAsal): number | undefined {
        const sejenjang = kelasTujuan.filter((t) => t.jenjang === k.jenjang);

        return (sejenjang.find((t) => t.nama.split(' ')[0] === k.nama.split(' ')[0]) ?? sejenjang[0])?.id;
    }

    function jalankan() {
        if (!confirm(`Jalankan kenaikan kelas? ${naik} naik kelas, ${jumlah.tinggal} tinggal kelas, ${jumlah.lulus} lulus.`)) {
            return;
        }

        const tujuanTerisi = Object.fromEntries(Object.entries(tujuanPerKelas).filter(([, v]) => v !== ''));
        router.post(
            TahunAjaranController.jalankanKenaikan.url(tujuan.id),
            { tujuan: tujuanTerisi, tinggal },
            { preserveScroll: true, onStart: () => setProses(true), onFinish: () => setProses(false) },
        );
    }

    if (!asal) {
        return (
            <div className="p-4 text-sm text-muted-foreground">
                <Head title="Kenaikan Kelas" />
                Belum ada tahun ajaran sebelum {tujuan.nama} yang punya kelas.
            </div>
        );
    }

    return (
        <>
            <Head title="Kenaikan Kelas" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={`Kenaikan kelas ${asal.nama} → ${tujuan.nama}`}
                    description="Pilih kelas tujuan tiap kelas (sudah diusulkan jenjang +1 dengan huruf sama). Kelas 6 lulus. Tandai siswa yang tinggal kelas lewat tombol Siswa."
                />

                {kelasTujuan.length === 0 && (
                    <p className="rounded-md border border-sekolah-tertunda/40 bg-sekolah-tertunda/10 p-3 text-sm text-sekolah-tertunda">
                        Tahun ajaran {tujuan.nama} belum punya kelas. Salin kelas dulu di halaman Kelas & Les.
                    </p>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Kelas {asal.nama}</th>
                                <th className="p-3 text-right">Siswa</th>
                                <th className="p-3">Naik ke kelas {tujuan.nama}</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {kelasAsal.map((k) => {
                                const belumDiKelas = k.siswa.filter((s) => !s.sudah_di);
                                const pilihan = kelasTujuan.filter((t) => t.jenjang === k.jenjang + 1);

                                return (
                                    <FragmenKelas key={k.id}>
                                        <tr className="border-t">
                                            <td className="p-3 font-medium whitespace-nowrap">{k.nama}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                {belumDiKelas.length}
                                                {belumDiKelas.length < k.siswa.length && (
                                                    <span className="block text-xs text-muted-foreground">{k.siswa.length - belumDiKelas.length} sudah berkelas</span>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                {k.jenjang === 6 ? (
                                                    <span className="rounded-full bg-sekolah-info/15 px-2 py-0.5 text-xs text-sekolah-info">Lulus</span>
                                                ) : (
                                                    <select
                                                        aria-label={`Kelas tujuan ${k.nama}`}
                                                        value={tujuanPerKelas[k.id]}
                                                        onChange={(e) => setTujuanPerKelas({ ...tujuanPerKelas, [k.id]: e.target.value === '' ? '' : Number(e.target.value) })}
                                                        className={cn(kelasPilihan, belumDiKelas.length > 0 && !tujuanPerKelas[k.id] && 'border-sekolah-tertunda/70')}
                                                    >
                                                        <option value="">Pilih kelas jenjang {k.jenjang + 1}</option>
                                                        {pilihan.map((t) => (
                                                            <option key={t.id} value={t.id}>
                                                                {t.nama}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                                {errors[`tujuan.${k.id}`] && <p className="mt-1 text-xs text-sekolah-bahaya">{errors[`tujuan.${k.id}`]}</p>}
                                            </td>
                                            <td className="p-3 text-right">
                                                {k.siswa.length > 0 && (
                                                    <Button size="sm" variant="ghost" onClick={() => setDibuka(dibuka === k.id ? null : k.id)}>
                                                        Siswa{k.siswa.some((s) => tinggal[s.id]) ? ` · ${k.siswa.filter((s) => tinggal[s.id]).length} tinggal` : ''}
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                        {dibuka === k.id && (
                                            <tr className="bg-muted/30">
                                                <td colSpan={4} className="p-3">
                                                    <ul className="grid gap-1 sm:grid-cols-2">
                                                        {k.siswa.map((s) => (
                                                            <li key={s.id}>
                                                                {s.sudah_di ? (
                                                                    <span className="text-muted-foreground">
                                                                        {s.nama} · sudah di {s.sudah_di}
                                                                    </span>
                                                                ) : (
                                                                    <label className="flex cursor-pointer items-center gap-2">
                                                                        <Checkbox
                                                                            checked={!!tinggal[s.id]}
                                                                            onCheckedChange={(v) => {
                                                                                const baru = { ...tinggal };
                                                                                const ke = kelasTinggal(k);

                                                                                if (v === true && ke) {
                                                                                    baru[s.id] = ke;
                                                                                } else {
                                                                                    delete baru[s.id];
                                                                                }

                                                                                setTinggal(baru);
                                                                            }}
                                                                        />
                                                                        {s.nama}
                                                                        {tinggal[s.id] && (
                                                                            <span className="text-xs text-sekolah-tertunda">
                                                                                tinggal kelas → {kelasTujuan.find((t) => t.id === tinggal[s.id])?.nama}
                                                                            </span>
                                                                        )}
                                                                    </label>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                    {!kelasTinggal(k) && (
                                                        <p className="mt-2 text-xs text-muted-foreground">Belum ada kelas jenjang {k.jenjang} di {tujuan.nama} untuk siswa tinggal kelas.</p>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </FragmenKelas>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button onClick={jalankan} disabled={proses || belum.length === 0 || kurangTujuan.length > 0}>
                        Jalankan kenaikan kelas
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        {belum.length === 0
                            ? 'Semua siswa sudah punya kelas di tahun ajaran tujuan.'
                            : `${naik} naik kelas · ${jumlah.tinggal} tinggal kelas · ${jumlah.lulus} lulus`}
                        {kurangTujuan.length > 0 && ` · pilih kelas tujuan untuk ${kurangTujuan.map((k) => k.nama).join(', ')}`}
                    </span>
                </div>
            </div>
        </>
    );
}

function FragmenKelas({ children }: { children: React.ReactNode }) {
    return <>{children}</>;
}

KenaikanKelas.layout = {
    breadcrumbs: [{ title: 'Tahun Ajaran', href: index() }, { title: 'Kenaikan Kelas' }],
};
