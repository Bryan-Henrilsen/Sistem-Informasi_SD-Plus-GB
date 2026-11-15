import { Link, usePage } from '@inertiajs/react';
import { House, ReceiptText, UserRound, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import ortu from '@/routes/ortu';
import type { Auth } from '@/types';

type Menu = { judul: string; href: string; ikon: LucideIcon; aktif: (url: string) => boolean };

// Menu bawah orang tua (Ringkasan v1.11 #36–#38); daftar kegiatan dibuka dari kartu anak
const MENU: Menu[] = [
    { judul: 'Beranda', href: ortu.beranda.url(), ikon: House, aktif: (url) => url === '/ortu' || url.startsWith('/ortu?') },
    { judul: 'Anak', href: ortu.anak.url(), ikon: Users, aktif: (url) => url.startsWith('/ortu/anak') },
    { judul: 'Tagihan', href: ortu.tagihan.url(), ikon: ReceiptText, aktif: (url) => url.startsWith('/ortu/tagihan') },
    { judul: 'Akun', href: ortu.akun.url(), ikon: UserRound, aktif: (url) => url.startsWith('/ortu/akun') },
];

// Layout khusus HP untuk orang tua: header sederhana + menu bawah, tanpa sidebar staf
export default function OrangTuaLayout({ children }: { children: React.ReactNode }) {
    const halaman = usePage<{ auth: Auth }>();
    const { auth } = halaman.props;
    const path = halaman.url;

    return (
        <div className="flex min-h-svh flex-col bg-muted/30">
            <header className="sticky top-0 z-10 border-b bg-background">
                <div className="mx-auto flex h-14 max-w-2xl items-center gap-3 px-4">
                    <AppLogoIcon className="size-7 fill-current" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">SD Plus Gembala Baik</p>
                        <p className="truncate text-xs text-muted-foreground">{auth.user?.name}</p>
                    </div>
                </div>
            </header>

            <main className="mx-auto w-full max-w-2xl flex-1 px-4 pt-4 pb-24">{children}</main>

            <nav className="fixed inset-x-0 bottom-0 z-10 border-t bg-background pb-[env(safe-area-inset-bottom)]">
                <div className="mx-auto grid max-w-2xl grid-cols-4">
                    {MENU.map((m) => {
                        const aktif = m.aktif(path);

                        return (
                            <Link
                                key={m.judul}
                                href={m.href}
                                className={cn('flex flex-col items-center gap-0.5 py-2 text-xs', aktif ? 'text-primary' : 'text-muted-foreground')}
                            >
                                <m.ikon className={cn('size-5', aktif && 'stroke-[2.5]')} />
                                {m.judul}
                            </Link>
                        );
                    })}
                </div>
            </nav>
        </div>
    );
}
