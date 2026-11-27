import { Form, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import PendaftaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PendaftaranController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';

export type PeriodePendaftaran = { id: number; tanggal_mulai: string; tanggal_selesai: string; status: 'buka' | 'akan_datang' | 'selesai' };

const LABEL = { buka: 'Sedang dibuka', akan_datang: 'Akan datang', selesai: 'Selesai' };

// Jendela pendaftaran oleh orang tua (FR-2, Ringkasan v1.11 #37); TU tetap bisa input manual kapan saja (FR-6)
export function PanelPeriodePendaftaran({ periode, bisaUbah }: { periode: PeriodePendaftaran[]; bisaUbah: boolean }) {
    return (
        <div className="flex flex-col gap-3 rounded-md border p-4">
            <div>
                <p className="font-medium">Periode pendaftaran orang tua</p>
                <p className="text-sm text-muted-foreground">
                    Di luar periode ini orang tua hanya bisa melihat kegiatan; TU tetap bisa mendaftarkan siswa kapan saja.
                </p>
            </div>

            {periode.length === 0 ? (
                <p className="text-sm text-muted-foreground">Belum ada periode untuk tahun ajaran ini.</p>
            ) : (
                <ul className="flex flex-col gap-1 text-sm">
                    {periode.map((p) => (
                        <li key={p.id} className="flex items-center gap-2">
                            <span className="tabular-nums">
                                {tanggal(p.tanggal_mulai)} – {tanggal(p.tanggal_selesai)}
                            </span>
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-xs',
                                    p.status === 'buka' ? 'bg-sekolah-sukses/15 text-sekolah-sukses' : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {LABEL[p.status]}
                            </span>
                            {bisaUbah && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    title="Hapus periode"
                                    onClick={() => router.delete(PendaftaranController.hapusPeriode(p.id).url, { preserveScroll: true })}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {bisaUbah && (
                <Form
                    {...PendaftaranController.simpanPeriode.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-wrap items-start gap-2 text-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-1">
                                <Input type="date" name="tanggal_mulai" aria-label="Tanggal mulai" className="w-40" />
                                <InputError message={errors.tanggal_mulai} />
                            </div>
                            <span className="mt-2">s.d.</span>
                            <div className="flex flex-col gap-1">
                                <Input type="date" name="tanggal_selesai" aria-label="Tanggal selesai" className="w-40" />
                                <InputError message={errors.tanggal_selesai} />
                            </div>
                            <Button type="submit" variant="outline" disabled={processing}>
                                Tambah periode
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
