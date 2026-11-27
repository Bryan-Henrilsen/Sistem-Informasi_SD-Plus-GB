import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import PengajarEkskulController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PengajarEkskulController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DialogPilihBanyak } from '@/components/les-ekskul/dialog-pilih-banyak';
import type { OpsiPilih } from '@/components/les-ekskul/dialog-pilih-banyak';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { tanggal } from '@/lib/format';

type Pengajar = {
    id: number;
    nama: string;
    jenis: 'internal' | 'eksternal';
    user_id: number | null;
    no_hp: string | null;
    status_aktif: boolean;
    jumlah_kegiatan: number;
};

type Kegiatan = {
    id: number;
    nama: string;
    kategori: 'seni' | 'olahraga' | null;
    per_kelas: boolean;
    punya_paralel: boolean;
    baris: Baris[]; // tempat pengajar dicatat
    unit: { id: number; nama: string; pendamping_ids: number[] }[]; // unit honor tempat pendamping dicatat
    masalah: string[];
};

// Riwayat penugasan, terisi bila pernah ada pergantian pengajar (#50)
type Riwayat = { id: number; nama: string; jenis: 'internal' | 'eksternal'; mulai: string | null; selesai: string | null };
type Baris = { id: number; nama: string; pengajar_ids: number[]; riwayat: Riwayat[] };

type Props = {
    pengajar: Pengajar[];
    kegiatan: Kegiatan[];
    masalahLes: string[];
    orang: { id: number; nama: string }[];
    bisaAturPendamping: boolean;
    bisaUbah: boolean;
};

type Pilihan = { judul: string; deskripsi?: string; opsi: OpsiPilih[]; terpilih: number[]; url: string; field: string };

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function PengajarEkskulIndex({ pengajar, kegiatan, masalahLes, orang, bisaAturPendamping, bisaUbah }: Props) {
    const [formPengajar, setFormPengajar] = useState<Pengajar | 'baru' | null>(null);
    const [pilihan, setPilihan] = useState<Pilihan | null>(null);
    const [ganti, setGanti] = useState<Baris | null>(null); // baris kegiatan yang pengajarnya diganti di tengah tahun

    const namaPengajar = new Map(pengajar.map((p) => [p.id, p]));
    const namaOrang = new Map(orang.map((o) => [o.id, o.nama]));
    const semuaMasalah = [...kegiatan.flatMap((k) => k.masalah), ...masalahLes];
    const opsiPengajar: OpsiPilih[] = pengajar
        .filter((p) => p.status_aktif)
        .map((p) => ({ id: p.id, label: p.nama, keterangan: p.jenis }));
    const opsiOrang: OpsiPilih[] = orang.map((o) => ({ id: o.id, label: o.nama }));

    const kirim = (url: string, data: Record<string, string | boolean | null>) => router.post(url, data, { preserveScroll: true });

    return (
        <>
            <Head title="Pengajar Ekskul" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Pengajar Ekskul"
                    description="Siapa mengajar dan mendampingi setiap ekskul, sebagai dasar pembagian honor. Pengajar les tambahan adalah wali kelasnya."
                />

                {semuaMasalah.length > 0 ? (
                    <div className="rounded-md border border-sekolah-tertunda/40 bg-sekolah-tertunda/10 p-3 text-sm text-sekolah-tertunda">
                        <p className="mb-1 font-medium">Data yang perlu dilengkapi sebelum honor bisa dihitung:</p>
                        <ul className="list-disc pl-5">
                            {semuaMasalah.map((m) => (
                                <li key={m}>{m}</li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <p className="rounded-md border border-sekolah-sukses/40 bg-sekolah-sukses/10 p-3 text-sm text-sekolah-sukses">
                        Data pengajar dan pendamping sudah lengkap untuk perhitungan honor.
                    </p>
                )}

                {/* Penugasan per kegiatan */}
                <div className="flex flex-col gap-4">
                    {kegiatan.map((k) => (
                        <Card key={k.id}>
                            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                <CardTitle>{k.nama}</CardTitle>
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    {k.punya_paralel && (
                                        <select
                                            value={k.per_kelas ? '1' : '0'}
                                            disabled={!bisaUbah}
                                            onChange={(e) => kirim(PengajarEkskulController.aturMode.url(k.id), { per_kelas: e.target.value === '1' })}
                                            className={kelasPilihan}
                                        >
                                            <option value="0">Honor digabung seluruh kelas</option>
                                            <option value="1">Honor per kelas paralel</option>
                                        </select>
                                    )}
                                    <select
                                        value={k.kategori ?? ''}
                                        disabled={!bisaAturPendamping}
                                        onChange={(e) => kirim(PengajarEkskulController.aturKategori.url(k.id), { kategori: e.target.value || null })}
                                        className={kelasPilihan}
                                        title="Kategori pendamping (pengelompokan rekap)"
                                    >
                                        <option value="">Kategori: –</option>
                                        <option value="seni">Kategori: seni</option>
                                        <option value="olahraga">Kategori: olahraga</option>
                                    </select>
                                </div>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 text-sm">
                                {k.baris.map((b) => (
                                    <div key={`p-${b.id}`} className="flex flex-col gap-1">
                                        <BarisPenugasan
                                            label={k.punya_paralel ? b.nama : 'Pengajar'}
                                            isi={b.pengajar_ids.map((id) => namaPengajar.get(id)).filter((p) => p !== undefined).map((p) => (
                                                <Badge key={p.id} variant={p.jenis === 'eksternal' ? 'outline' : 'secondary'}>
                                                    {p.nama} · {p.jenis}
                                                </Badge>
                                            ))}
                                            tombol={
                                                bisaUbah && (
                                                    <span className="flex gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setPilihan({
                                                                    judul: `Pengajar ${b.nama}`,
                                                                    deskripsi: 'Untuk membetulkan salah input. Pergantian pengajar di tengah tahun pakai tombol Ganti pengajar.',
                                                                    opsi: opsiPengajar,
                                                                    terpilih: b.pengajar_ids,
                                                                    url: PengajarEkskulController.aturPengajar.url(b.id),
                                                                    field: 'pengajar_ids',
                                                                })
                                                            }
                                                        >
                                                            Atur pengajar
                                                        </Button>
                                                        {b.pengajar_ids.length > 0 && (
                                                            <Button size="sm" variant="ghost" onClick={() => setGanti(b)}>
                                                                Ganti pengajar
                                                            </Button>
                                                        )}
                                                    </span>
                                                )
                                            }
                                        />
                                        {b.riwayat.length > 0 && (
                                            <ul className="text-xs text-muted-foreground sm:pl-50">
                                                {b.riwayat.map((r) => (
                                                    <li key={r.id}>
                                                        {r.nama} · {r.jenis}
                                                        {r.mulai && ` · mulai ${tanggal(r.mulai)}`}
                                                        {r.selesai ? ` · s.d. ${tanggal(r.selesai)}` : ' · sekarang'}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                ))}

                                {k.unit.map((u) => (
                                    <BarisPenugasan
                                        key={`d-${u.id}`}
                                        label={k.unit.length > 1 || k.punya_paralel ? `Pendamping ${u.nama}` : 'Pendamping'}
                                        isi={u.pendamping_ids.map((id) => (
                                            <Badge key={id} variant="secondary">
                                                {namaOrang.get(id) ?? `#${id}`}
                                            </Badge>
                                        ))}
                                        tombol={
                                            bisaAturPendamping && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setPilihan({
                                                            judul: `Pendamping ${u.nama}`,
                                                            deskripsi:
                                                                'Wajib untuk pengajar eksternal (5%). Pendamping kedua dan seterusnya dibayar dari porsi kas sekolah (FR-16d).',
                                                            opsi: opsiOrang,
                                                            terpilih: u.pendamping_ids,
                                                            url: PengajarEkskulController.aturPendamping.url(u.id),
                                                            field: 'user_ids',
                                                        })
                                                    }
                                                >
                                                    Atur pendamping
                                                </Button>
                                            )
                                        }
                                    />
                                ))}

                                {k.masalah.length > 0 && (
                                    <ul className="list-disc pl-5 text-xs text-sekolah-bahaya">
                                        {k.masalah.map((m) => (
                                            <li key={m}>{m}</li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Daftar pengajar */}
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between">
                        <h3 className="font-medium">Daftar pengajar ekskul</h3>
                        {bisaUbah && <Button onClick={() => setFormPengajar('baru')}>Tambah pengajar</Button>}
                    </div>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Nama</th>
                                    <th className="p-3">Jenis</th>
                                    <th className="p-3">No HP</th>
                                    <th className="p-3">Kegiatan</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {pengajar.map((p) => (
                                    <tr key={p.id} className="border-t">
                                        <td className="p-3 font-medium">{p.nama}</td>
                                        <td className="p-3 capitalize">{p.jenis}</td>
                                        <td className="p-3 text-muted-foreground">{p.no_hp ?? '–'}</td>
                                        <td className="p-3">{p.jumlah_kegiatan}</td>
                                        <td className="p-3">{p.status_aktif ? 'Aktif' : 'Tidak aktif'}</td>
                                        <td className="p-3">
                                            {bisaUbah && (
                                                <Button size="sm" variant="ghost" onClick={() => setFormPengajar(p)}>
                                                    Ubah
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {pengajar.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                            Belum ada pengajar ekskul.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <DialogPengajar data={formPengajar} orang={orang} onClose={() => setFormPengajar(null)} />
            <Dialog open={ganti !== null} onOpenChange={(b) => !b && setGanti(null)}>
                <DialogContent>
                    {ganti && <FormGantiPengajar baris={ganti} pengajar={pengajar} onClose={() => setGanti(null)} />}
                </DialogContent>
            </Dialog>
            <DialogPilihBanyak
                buka={pilihan !== null}
                judul={pilihan?.judul ?? ''}
                deskripsi={pilihan?.deskripsi}
                opsi={pilihan?.opsi ?? []}
                terpilih={pilihan?.terpilih ?? []}
                url={pilihan?.url ?? ''}
                field={pilihan?.field ?? ''}
                onClose={() => setPilihan(null)}
            />
        </>
    );
}

function BarisPenugasan({ label, isi, tombol }: { label: string; isi: React.ReactNode[]; tombol: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="w-48 text-muted-foreground">{label}</span>
            <span className="flex flex-1 flex-wrap gap-1">{isi.length > 0 ? isi : <span className="text-muted-foreground">–</span>}</span>
            {tombol}
        </div>
    );
}

// Pergantian pengajar di tengah tahun (#50): honor bulan pergantian dibagi per pertemuan sesuai jadwal kegiatan.
// Pengajar baru sebaiknya berjenis sama (eksternal → eksternal); beda jenis di satu periode menahan perhitungan honor
function FormGantiPengajar({ baris, pengajar, onClose }: { baris: Baris; pengajar: Pengajar[]; onClose: () => void }) {
    const sekarang = pengajar.filter((p) => baris.pengajar_ids.includes(p.id));
    const calon = pengajar.filter((p) => p.status_aktif && !baris.pengajar_ids.includes(p.id));

    return (
        <Form
            {...PengajarEkskulController.gantiPengajar.form(baris.id)}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ganti pengajar {baris.nama}</DialogTitle>
                        <DialogDescription>
                            Honor bulan pergantian dibagi menurut jumlah pertemuan (hari jadwal kegiatan) setiap pengajar. Pilih pengajar baru yang
                            jenisnya sama; internal dan eksternal tercampur di satu periode membuat honor belum bisa dihitung.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="pengajar_lama_id">Pengajar lama</Label>
                        <select id="pengajar_lama_id" name="pengajar_lama_id" defaultValue={sekarang.length === 1 ? sekarang[0].id : ''} className={kelasPilihan}>
                            {sekarang.length > 1 && <option value="">Pilih</option>}
                            {sekarang.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.nama} · {p.jenis}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.pengajar_lama_id ?? errors.kegiatan} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal_terakhir">Tanggal terakhir pengajar lama mengajar</Label>
                        <Input id="tanggal_terakhir" name="tanggal_terakhir" type="date" />
                        <InputError message={errors.tanggal_terakhir} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="pengajar_baru_id">Pengajar baru</Label>
                        <select id="pengajar_baru_id" name="pengajar_baru_id" defaultValue="" className={kelasPilihan}>
                            <option value="">Pilih</option>
                            {calon.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.nama} · {p.jenis}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-muted-foreground">Pengajar baru yang belum terdaftar ditambahkan dulu lewat Tambah pengajar.</p>
                        <InputError message={errors.pengajar_baru_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal_mulai">Tanggal mulai pengajar baru</Label>
                        <Input id="tanggal_mulai" name="tanggal_mulai" type="date" />
                        <InputError message={errors.tanggal_mulai} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Simpan pergantian
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

// Tambah/ubah pengajar: internal = pilih akun guru/staf, eksternal = nama tanpa akun
function DialogPengajar({ data, orang, onClose }: { data: Pengajar | 'baru' | null; orang: Props['orang']; onClose: () => void }) {
    return (
        <Dialog open={data !== null} onOpenChange={(b) => !b && onClose()}>
            <DialogContent>{data !== null && <IsiPengajar data={data} orang={orang} onClose={onClose} />}</DialogContent>
        </Dialog>
    );
}

function IsiPengajar({ data, orang, onClose }: { data: Pengajar | 'baru'; orang: Props['orang']; onClose: () => void }) {
    const lama = data === 'baru' ? null : data;
    const [jenis, setJenis] = useState<'internal' | 'eksternal'>(lama?.jenis ?? 'internal');

    return (
        <Form
            {...(lama ? PengajarEkskulController.update.form(lama.id) : PengajarEkskulController.store.form())}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>{lama ? `Ubah ${lama.nama}` : 'Tambah pengajar'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="jenis">Jenis</Label>
                        <select id="jenis" name="jenis" value={jenis} onChange={(e) => setJenis(e.target.value as 'internal' | 'eksternal')} className={kelasPilihan}>
                            <option value="internal">Internal (guru/staf SD Plus)</option>
                            <option value="eksternal">Eksternal (pelatih dari luar)</option>
                        </select>
                    </div>

                    {jenis === 'internal' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="user_id">Akun guru/staf</Label>
                            <select id="user_id" name="user_id" defaultValue={lama?.user_id ?? ''} className={kelasPilihan}>
                                <option value="">Pilih</option>
                                {orang.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.nama}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.user_id} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="nama">Nama pelatih</Label>
                            <Input id="nama" name="nama" defaultValue={lama?.nama ?? ''} />
                            <InputError message={errors.nama} />
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="no_hp">No HP</Label>
                        <Input id="no_hp" name="no_hp" defaultValue={lama?.no_hp ?? ''} />
                        <InputError message={errors.no_hp} />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input type="hidden" name="status_aktif" value="0" />
                        <input type="checkbox" name="status_aktif" value="1" defaultChecked={lama?.status_aktif ?? true} className="size-4 accent-primary" />
                        Aktif mengajar
                    </label>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Simpan
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

PengajarEkskulIndex.layout = {
    breadcrumbs: [{ title: 'Pengajar Ekskul', href: PengajarEkskulController.index.url() }],
};
