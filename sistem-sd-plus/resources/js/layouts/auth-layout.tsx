// Desain sekolah (krem & cokelat) untuk semua halaman auth
import AuthLayoutTemplate from '@/layouts/auth/auth-sekolah-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description}>
            {children}
        </AuthLayoutTemplate>
    );
}
