import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

// Layout halaman Masuk, Daftar, Lupa & Atur ulang password (desain sekolah, docs/komponen-referensi.html).
// Layar lebar: panel cokelat berisi logo & motto di kiri, formulir di kanan. HP: logo lalu formulir.
// Kelas tema-sekolah membuat komponen bawaan (tombol, input) ikut memakai palet sekolah, termasuk versi gelapnya
export default function AuthSekolahLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="tema-sekolah grid min-h-svh bg-sekolah-latar text-sekolah-teks lg:grid-cols-[1fr_1.1fr]">
            <aside className="relative hidden flex-col justify-between bg-peran-kepsek p-10 text-white lg:flex dark:border-r dark:border-sekolah-garis dark:bg-sekolah-kartu dark:text-sekolah-teks">
                <Link href={home()} className="inline-flex w-fit items-center gap-2 text-sm text-white/80 hover:text-white dark:text-sekolah-redup dark:hover:text-sekolah-teks">
                    <ArrowLeft className="size-4" /> Halaman depan
                </Link>

                <div className="flex flex-col items-center gap-6 text-center">
                    <div className="rounded-2xl bg-white p-5 shadow-sm">
                        <AppLogoIcon className="h-52" />
                    </div>
                    <div className="space-y-1">
                        <p className="text-2xl font-bold">SD Plus Gembala Baik</p>
                        <p className="text-white/80 dark:text-sekolah-redup">Sistem Informasi Sekolah</p>
                    </div>
                </div>

                <figure className="mx-auto max-w-sm text-center">
                    <blockquote className="text-lg font-medium italic">Growing Together</blockquote>
                    <figcaption className="mt-1 text-sm text-white/75 dark:text-sekolah-redup">Shining Forever</figcaption>
                </figure>
            </aside>

            <main className="flex flex-col items-center justify-center gap-6 px-4 py-10 sm:px-6">
                <div className="flex w-full max-w-sm flex-col gap-6">
                    {/* Di HP panel kiri disembunyikan: logo & nama sekolah tampil di atas formulir */}
                    <Link href={home()} className="flex flex-col items-center gap-2 lg:hidden">
                        <AppLogoIcon className="h-20" alt="" />
                        <span className="text-sm font-bold">SD Plus Gembala Baik</span>
                    </Link>

                    <div className="space-y-1.5 text-center lg:text-left">
                        <h1 className="text-2xl font-bold text-sekolah-utama-tua">{title}</h1>
                        {description && <p className="text-sm text-sekolah-redup">{description}</p>}
                    </div>

                    <div className="rounded-lg border border-sekolah-garis bg-sekolah-kartu p-5 sm:p-6">{children}</div>
                </div>
            </main>
        </div>
    );
}
