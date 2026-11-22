import { Link, usePage } from '@inertiajs/react';
import AkunOrangTuaController from '@/actions/App/Domains/LesEkskul/Http/Controllers/AkunOrangTuaController';
import PengajarEkskulController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PengajarEkskulController';
import RekapHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/RekapHonorController';
import SkemaHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/SkemaHonorController';
import {
    BookMarked,
    BookOpen,
    CalendarRange,
    ClipboardList,
    Coins,
    Contact,
    FileCheck2,
    FolderGit2,
    Landmark,
    LayoutGrid,
    Percent,
    ReceiptText,
    Shapes,
    School,
    GraduationCap,
    CalendarDays,
    UserCog,
    Undo2,
    Users,
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
import laporanKas from '@/routes/les-ekskul/laporan-kas';
import ortu from '@/routes/ortu';
import pendaftaran from '@/routes/les-ekskul/pendaftaran';
import periode from '@/routes/les-ekskul/periode';
import refund from '@/routes/les-ekskul/refund';
import tagihan from '@/routes/les-ekskul/tagihan';
import ekstrakurikuler from '@/routes/les-ekskul/ekstrakurikuler';
import kelas from '@/routes/les-ekskul/kelas';
import dataSiswa from '@/routes/les-ekskul/data-siswa';
import tahunAjaran from '@/routes/les-ekskul/tahun-ajaran';
import akunStaf from '@/routes/les-ekskul/akun-staf';
import mataPelajaran from '@/routes/les-ekskul/mata-pelajaran';
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
    { title: 'Rekap Iuran', href:tagihan.index(), icon: ReceiptText },
    { title: 'Transaksi', href: transaksi.index(), icon: Wallet },
    { title: 'Laporan Harian', href: laporanHarian.index(), icon: FileCheck2 },
    { title: 'Pendaftaran', href: pendaftaran.index(), icon: ClipboardList },
    { title: 'Kegiatan Ekskul', href: ekstrakurikuler.index(), icon: Shapes },
    { title: 'Refund', href: refund.index(), icon: Undo2 },
    { title: 'Laporan Kas', href: laporanKas.index(), icon: Landmark },
];

// Menu pengaturan dan rekap honor (FR-16–FR-20); role sama dengan menu keuangan
const honorNavItems: NavItem[] = [
    { title: 'Pengajar Ekskul', href: PengajarEkskulController.index(), icon: Users },
    { title: 'Skema Honor', href: SkemaHonorController.index(), icon: Percent },
    { title: 'Rekap Honor', href: RekapHonorController.index(), icon: Coins },
];
const roleKeuangan = ['tu', 'bendahara', 'kepala_sekolah'];

// Kelompok Data Sekolah (#47): data master bersama; tiap menu hanya untuk role yang diizinkan route-nya
const dataSekolahNavItems: (NavItem & { roles: string[] })[] = [
    { title: 'Tahun Ajaran', href: tahunAjaran.index(), icon: CalendarDays, roles: roleKeuangan },
    { title: 'Kelas & Les', href: kelas.index(), icon: School, roles: roleKeuangan },
    { title: 'Data Siswa', href: dataSiswa.index(), icon: GraduationCap, roles: roleKeuangan },
    { title: 'Akun Guru & Staf', href: akunStaf.index(), icon: UserCog, roles: ['kepala_sekolah'] },
    { title: 'Mata Pelajaran', href: mataPelajaran.index(), icon: BookMarked, roles: ['waka_kurikulum', 'kepala_sekolah'] },
];

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
    const menuDataSekolah: NavItem[] = dataSekolahNavItems
        .filter((item) => item.roles.some((role) => auth.roles.includes(role)))
        .map(({ roles: _roles, ...item }) => item);
    // Akun Orang Tua hanya untuk TU (#36)
    if (auth.roles.includes('tu')) {
        menuLesEkskul.push({ title: 'Akun Orang Tua', href: AkunOrangTuaController.index(), icon: Contact, badge: undefined });
    }
    // Guru/staf yang juga orang tua membuka halaman orang tua dari sini (#36)
    const menuOrangTua: NavItem[] = auth.roles.includes('orang_tua') ? [{ title: 'Anak Saya', href: ortu.beranda(), icon: Users }] : [];

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
                {bisaLihatKeuangan && <NavMain items={honorNavItems} label="Honor" />}
                {menuDataSekolah.length > 0 && <NavMain items={menuDataSekolah} label="Data Sekolah" />}
                {menuOrangTua.length > 0 && <NavMain items={menuOrangTua} label="Orang Tua" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
