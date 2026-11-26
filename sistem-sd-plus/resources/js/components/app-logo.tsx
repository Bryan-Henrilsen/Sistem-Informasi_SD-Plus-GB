import AppLogoIcon from '@/components/app-logo-icon';

// Logo + nama sekolah di sidebar dan header aplikasi
export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="h-8 shrink-0" alt="" />
            <div className="ml-1 grid flex-1 text-left text-sm leading-tight">
                <span className="truncate font-semibold">SD Plus Gembala Baik</span>
                <span className="truncate text-xs text-muted-foreground">Sistem Informasi Sekolah</span>
            </div>
        </>
    );
}
