import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarRange,
    ClipboardList,
    FileCheck2,
    FolderGit2,
    LayoutGrid,
    ReceiptText,
    Undo2,
    Wallet,
    } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import laporanHarian from '@/routes/les-ekskul/laporan-harian';
import pendaftaran from '@/routes/les-ekskul/pendaftaran';
import periode from '@/routes/les-ekskul/periode';
import refund from '@/routes/les-ekskul/refund';
import tagihan from '@/routes/les-ekskul/tagihan';
import transaksi from '@/routes/les-ekskul/transaksi';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Beranda',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Menu modul Les & Ekskul; hanya tampil untuk role keuangan (sama dengan middleware di routes/les-ekskul.php)
const lesEkskulNavItems: NavItem[] = [
    { title: 'Periode Laporan', href: periode.index(), icon: CalendarRange },
    { title: 'Tagihan', href:tagihan.index(), icon: ReceiptText },
    { title: 'Transaksi', href: transaksi.index(), icon: Wallet },
    { title: 'Laporan Harian', href: laporanHarian.index(), icon: FileCheck2 },
    { title: 'Pendaftaran', href: pendaftaran.index(), icon: ClipboardList },
    { title: 'Refund', href: refund.index(), icon: Undo2 },
];
const roleKeuangan = ['tu', 'bendahara', 'kepala_sekolah'];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

type Badge = { transaksi: number; laporan_harian: number; refund: number } | null;

export function AppSidebar() {
    const { auth, badge } = usePage<{ auth: Auth; badge: Badge }>().props;
    const bisaLihatKeuangan = auth.roles.some((role) =>
        roleKeuangan.includes(role),
    );

    // Angka yang menunggu tindakan role pengguna (HandleInertiaRequests -> RingkasanTugasService::badge)
    const jumlah: Record<string, number | undefined> = {
        Transaksi: badge?.transaksi,
        'Laporan Harian': badge?.laporan_harian,
        Refund: badge?.refund,
    };
    const menuLesEkskul = lesEkskulNavItems.map((item) => ({ ...item, badge: jumlah[item.title] }));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {bisaLihatKeuangan && (
                    <NavMain items={menuLesEkskul} label="Les & Ekskul" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
