import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import ortu from '@/routes/ortu';
import { KartuAnak } from './beranda';
import type { AnakRingkas } from './beranda';

type KelasKlaim = { id: number; nama: string; siswa: { id: number; nama: string; diklaim: boolean }[] };

const kelasPilihan = 'h-11 w-full rounded-md border border-input bg-background px-3 text-base shadow-xs';

// Anak Saya: daftar anak yang sudah diklaim dan klaim anak baru — pilih kelas lalu nama (FR-1b)
export default function AnakSaya({ anak, kelas }: { anak: AnakRingkas[]; kelas: KelasKlaim[] }) {
    const [lepas, setLepas] = useState<AnakRingkas | null>(null);

    return (
        <>
            <Head title="Anak Saya" />

            <div className="flex flex-col gap-4">
                <h1 className="text-lg font-semibold">Anak Saya</h1>

                {anak.map((a) => (
                    <KartuAnak
                        key={a.id}
                        anak={a}
                        aksi={
                            <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={() => setLepas(a)}>
                                Hapus
                            </Button>
                        }
                    />
                ))}

                <FormKlaim kelas={kelas} adaAnak={anak.length > 0} />
            </div>

            <Dialog open={lepas !== null} onOpenChange={(b) => !b && setLepas(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus {lepas?.nama} dari daftar anak?</DialogTitle>
                        <DialogDescription>
                            Pakai ini bila Anda salah memilih anak. Data kegiatan dan pembayaran anak tidak terhapus, dan Anda bisa menambahkannya lagi.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setLepas(null)}>
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                lepas &&
                                router.delete(ortu.anak.lepas(lepas.id).url, { preserveScroll: true, onSuccess: () => setLepas(null) })
                            }
                        >
                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function FormKlaim({ kelas, adaAnak }: { kelas: KelasKlaim[]; adaAnak: boolean }) {
    const [kelasId, setKelasId] = useState('');
    const [siswaId, setSiswaId] = useState('');
    const dipilih = kelas.find((k) => String(k.id) === kelasId);

    return (
        <Card className="gap-3 py-5">
            <CardHeader className="px-5">
                <CardTitle className="text-base">{adaAnak ? 'Tambah anak lain' : 'Tambahkan anak Anda'}</CardTitle>
                <p className="text-sm text-muted-foreground">Pilih kelas anak tahun ajaran ini, lalu pilih namanya.</p>
            </CardHeader>
            <CardContent className="px-5">
                <Form
                    {...ortu.anak.klaim.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setKelasId('');
                        setSiswaId('');
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="kelas">Kelas</Label>
                                <select
                                    id="kelas"
                                    value={kelasId}
                                    onChange={(e) => {
                                        setKelasId(e.target.value);
                                        setSiswaId('');
                                    }}
                                    className={kelasPilihan}
                                >
                                    <option value="">Pilih kelas</option>
                                    {kelas.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.nama}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="siswa_id">Nama anak</Label>
                                <select
                                    id="siswa_id"
                                    name="siswa_id"
                                    value={siswaId}
                                    onChange={(e) => setSiswaId(e.target.value)}
                                    disabled={!dipilih}
                                    className={kelasPilihan}
                                >
                                    <option value="">{dipilih ? 'Pilih nama' : 'Pilih kelas dulu'}</option>
                                    {dipilih?.siswa.map((s) => (
                                        <option key={s.id} value={s.id} disabled={s.diklaim}>
                                            {s.nama}
                                            {s.diklaim ? ' (sudah di daftar Anda)' : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.siswa_id} />
                            </div>

                            <Button type="submit" className="h-11" disabled={processing || siswaId === ''}>
                                Tambahkan
                            </Button>

                            <p className="text-sm text-muted-foreground">Nama anak tidak ada di daftar? Hubungi Tata Usaha (TU) sekolah.</p>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
