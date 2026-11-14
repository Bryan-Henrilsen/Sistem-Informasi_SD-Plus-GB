import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarRange,
    ClipboardList,
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
import pendaftaran from '@/routes/les-ekskul/pendaftaran';
import periode from '@/routes/les-ekskul/periode';
import refund from '@/routes/les-ekskul/refund';
import tagihan from '@/routes/les-ekskul/tagihan';
import transaksi from '@/routes/les-ekskul/transaksi';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Menu modul Les & Ekskul; hanya tampil untuk role keuangan (sama dengan middleware di routes/les-ekskul.php)
const lesEkskulNavItems: NavItem[] = [
    { title: 'Periode Laporan', href: periode.index(), icon: CalendarRange },
    { title: 'Tagihan', href:tagihan.index(), icon: ReceiptText },
    { title: 'Transaksi', href: transaksi.index(), icon: Wallet },
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

export function AppSidebar() {
    const { auth } = usePage<{ auth: Auth }>().props;
    const bisaLihatKeuangan = auth.roles.some((role) =>
        roleKeuangan.includes(role),
    );

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
                    <NavMain items={lesEkskulNavItems} label="Les & Ekskul" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
