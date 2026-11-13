import { Form, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import type { SiswaRingkas } from '@/types/les-ekskul';
import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';

type Props = {
    cari: string;
    hasilCari: SiswaRingkas[];
    form: RouteFormDefinition<'get'>; // halaman yang sama dengan ?cari=...
    tautanSiswa: (id: number) => RouteDefinition<'get'>; // halaman yang sama dengan ?siswa=...
};

// Langkah pertama halaman Catat Pembayaran dan Tambah Pendaftaran: cari siswa berdasarkan nama, lalu pilih
export function CariSiswa({ cari, hasilCari, form, tautanSiswa }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Pilih siswa</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Form {...form} className="flex gap-2">
                    <Input name="cari" defaultValue={cari} placeholder="Ketik nama siswa" autoFocus />
                    <Button type="submit" variant="secondary">
                        Cari
                    </Button>
                </Form>

                {cari && hasilCari.length === 0 && (
                    <p className="text-sm text-muted-foreground">Siswa tidak ditemukan.</p>
                )}

                <ul className="divide-y rounded-md border empty:hidden">
                    {hasilCari.map((s) => (
                        <li key={s.id}>
                            <Link href={tautanSiswa(s.id)} className="flex justify-between p-3 hover:bg-muted/50">
                                <span className="font-medium">{s.nama}</span>
                                <span className="text-sm text-muted-foreground">{s.kelas}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
