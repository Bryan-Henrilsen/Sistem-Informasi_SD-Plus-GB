import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, AtSign, CalendarClock, Facebook, Globe, GraduationCap, Instagram, LogIn, Mail, MessageCircle, MessagesSquare, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login, register } from '@/routes';
import type { Auth } from '@/types';

// Halaman depan sekolah (pengganti halaman bawaan Laravel). Warna & elemen mengikuti docs/komponen-referensi.html
// lewat palet sekolah di app.css (bg-sekolah-latar, text-sekolah-utama, …), termasuk versi gelapnya.
// Dibuat ramah HP karena orang tua kebanyakan membuka lewat HP

const fitur = [
    {
        ikon: GraduationCap,
        judul: 'Les & Ekstrakurikuler',
        isi: 'Pendaftaran les tambahan dan ekstrakurikuler, tagihan bulanan, riwayat pembayaran, dan konfirmasi transfer.',
        untuk: [
            ['Orang Tua', 'bg-peran-ortu'],
            ['TU', 'bg-peran-tu'],
            ['Bendahara', 'bg-peran-bendahara'],
            ['Kepala Sekolah', 'bg-peran-kepsek'],
        ],
    },
    {
        ikon: MessagesSquare,
        judul: 'Jurnal Guru',
        isi: 'Jadwal mengajar pribadi guru dan forum privat untuk menyampaikan pesan kepada Kepala Sekolah.',
        untuk: [
            ['Guru', 'bg-peran-guru'],
            ['Kepala Sekolah', 'bg-peran-kepsek'],
        ],
    },
    {
        ikon: CalendarClock,
        judul: 'Jadwal Guru',
        isi: 'Waktu pelajaran, kisi-kisi tugas, dan pembagian jadwal mengajar, piket, serta damping.',
        untuk: [
            ['Waka Kurikulum', 'bg-sekolah-netral'],
            ['Kepala Sekolah', 'bg-peran-kepsek'],
        ],
    },
];

const langkahOrangTua = [
    'Daftar akun memakai nomor HP Anda.',
    'Pilih kelas lalu nama anak untuk menghubungkannya ke akun.',
    'Daftarkan kegiatan dan pantau tagihan serta pembayarannya.',
];

export default function Welcome({ waTu }: { waTu: string | null }) {
    const { auth } = usePage<{ auth: Auth }>().props;

    const tautan = [
        { label: 'Website sekolah', href: 'https://sdplusgembalabaik.sch.id/', ikon: Globe },
        { label: 'sdplusgembalabaikptk@gmail.com', href: 'mailto:sdplusgembalabaikptk@gmail.com', ikon: Mail },
        ...(waTu ? [{ label: 'WhatsApp TU', href: `https://wa.me/${waTu}`, ikon: MessageCircle }] : []),
        { label: 'Instagram', href: 'https://www.instagram.com/sdplusgembalabaik/', ikon: Instagram },
        { label: 'Facebook', href: 'https://www.facebook.com/sdplusgembalabaik/?locale=id_ID', ikon: Facebook },
        { label: 'Threads', href: 'https://www.threads.com/@sdplusgembalabaik', ikon: AtSign },
    ];

    return (
        <>
            <Head title="Selamat datang" />

            <div className="min-h-svh bg-sekolah-latar text-sekolah-teks">
                <header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href="/" className="flex items-center gap-3">
                        <AppLogoIcon className="h-11" alt="" />
                        <div className="leading-tight">
                            <p className="font-bold">SD Plus Gembala Baik</p>
                            <p className="text-xs text-sekolah-redup">Pontianak</p>
                        </div>
                    </Link>
                    {auth.user ? (
                        <TombolUtama href={dashboard()}>
                            Buka Beranda <ArrowRight className="size-4" />
                        </TombolUtama>
                    ) : (
                        <TombolUtama href={login()}>
                            <LogIn className="size-4" /> Masuk
                        </TombolUtama>
                    )}
                </header>

                <main className="mx-auto w-full max-w-6xl px-4 sm:px-6">
                    {/* Bagian utama */}
                    <section className="grid items-center gap-10 py-10 md:grid-cols-[1.3fr_1fr] md:py-16">
                        <div className="flex flex-col gap-5">
                            <span className="w-fit rounded-full border border-sekolah-utama/30 bg-sekolah-utama/10 px-3 py-1 text-xs font-medium text-sekolah-utama-tua">
                                Sistem Informasi Sekolah
                            </span>
                            <h1 className="text-3xl leading-tight font-bold text-sekolah-utama-tua sm:text-4xl lg:text-5xl">
                                Sistem Informasi SD Plus Gembala Baik
                            </h1>
                            <p className="max-w-xl text-base text-sekolah-redup sm:text-lg">
                                Satu tempat untuk pendaftaran les dan ekstrakurikuler, tagihan dan pembayaran, jadwal mengajar, serta komunikasi guru dengan
                                Kepala Sekolah.
                            </p>
                            {auth.user ? (
                                <div className="flex flex-wrap gap-3">
                                    <TombolUtama href={dashboard()} besar>
                                        Buka Beranda <ArrowRight className="size-4" />
                                    </TombolUtama>
                                </div>
                            ) : (
                                <>
                                    <div className="flex flex-wrap gap-3">
                                        <TombolUtama href={login()} besar>
                                            <LogIn className="size-4" /> Masuk
                                        </TombolUtama>
                                        <Link
                                            href={register()}
                                            className="inline-flex items-center gap-2 rounded-md border border-sekolah-garis bg-sekolah-kartu px-5 py-2.5 text-sm font-medium transition-colors hover:border-sekolah-utama hover:text-sekolah-utama-tua"
                                        >
                                            <UserPlus className="size-4" /> Daftar akun orang tua
                                        </Link>
                                    </div>
                                    <p className="text-sm text-sekolah-redup">Orang tua masuk dengan nomor HP; guru dan staf dengan email sekolah.</p>
                                </>
                            )}
                        </div>

                        <div className="flex justify-center">
                            <div className="rounded-2xl border border-sekolah-garis bg-sekolah-kartu p-6 sm:p-8">
                                <AppLogoIcon className="h-56 sm:h-72" />
                            </div>
                        </div>
                    </section>

                    {/* Modul aplikasi */}
                    <section className="flex flex-col gap-4 pb-12">
                        <h2 className="text-lg font-bold">Yang bisa dilakukan di sini</h2>
                        <div className="grid gap-4 md:grid-cols-3">
                            {fitur.map((f) => (
                                <article key={f.judul} className="flex flex-col gap-3 rounded-lg border border-sekolah-garis bg-sekolah-kartu p-5">
                                    <span className="flex size-10 items-center justify-center rounded-md bg-sekolah-utama/10 text-sekolah-utama">
                                        <f.ikon className="size-5" />
                                    </span>
                                    <h3 className="text-lg font-bold">{f.judul}</h3>
                                    <p className="text-sm text-sekolah-redup">{f.isi}</p>
                                    <div className="mt-auto flex flex-wrap gap-1.5 pt-1">
                                        {f.untuk.map(([peran, warna]) => (
                                            <span key={peran} className={`rounded-full px-2.5 py-0.5 text-xs font-medium text-white ${warna}`}>
                                                {peran}
                                            </span>
                                        ))}
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>

                    {/* Panduan singkat orang tua */}
                    {!auth.user && (
                        <section className="mb-14 rounded-lg border border-sekolah-garis bg-sekolah-kartu p-5 sm:p-6">
                            <h2 className="text-lg font-bold">Untuk orang tua</h2>
                            <ol className="mt-4 grid gap-4 sm:grid-cols-3">
                                {langkahOrangTua.map((langkah, i) => (
                                    <li key={langkah} className="flex gap-3">
                                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-sekolah-utama text-sm font-bold text-sekolah-di-utama">
                                            {i + 1}
                                        </span>
                                        <span className="text-sm">{langkah}</span>
                                    </li>
                                ))}
                            </ol>
                            <Link href={register()} className="mt-5 inline-flex items-center gap-1 text-sm font-medium text-sekolah-utama hover:text-sekolah-utama-tua">
                                Daftar sekarang <ArrowRight className="size-4" />
                            </Link>
                        </section>
                    )}
                </main>

                <footer className="border-t border-sekolah-garis">
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 md:flex-row md:items-start md:justify-between">
                        <div className="flex items-center gap-3">
                            <AppLogoIcon className="h-12" alt="" />
                            <div className="text-sm leading-tight">
                                <p className="font-bold">SD Plus Gembala Baik</p>
                                <p className="text-sekolah-redup">Pontianak</p>
                            </div>
                        </div>
                        <ul className="grid gap-2 text-sm sm:grid-cols-2">
                            {tautan.map((t) => (
                                <li key={t.href}>
                                    <a href={t.href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 text-sekolah-redup hover:text-sekolah-utama">
                                        <t.ikon className="size-4 shrink-0" /> {t.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <p className="pb-6 text-center text-xs text-sekolah-redup">© {new Date().getFullYear()} SD Plus Gembala Baik</p>
                </footer>
            </div>
        </>
    );
}

function TombolUtama({ href, besar = false, children }: { href: Parameters<typeof Link>[0]['href']; besar?: boolean; children: ReactNode }) {
    return (
        <Link
            href={href}
            className={`inline-flex items-center gap-2 rounded-md bg-sekolah-utama font-medium text-sekolah-di-utama transition-colors hover:bg-sekolah-utama-tua ${besar ? 'px-5 py-2.5 text-sm' : 'px-4 py-2 text-sm'}`}
        >
            {children}
        </Link>
    );
}
