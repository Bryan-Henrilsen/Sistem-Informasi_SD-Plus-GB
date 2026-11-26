import type { ImgHTMLAttributes } from 'react';

// Logo SD Plus Gembala Baik (pengganti logo Laravel). Berkas sumber asli: public/Logo_SD_Plus_2026-2027.webp;
// yang dimuat versi kecil public/images/logo-sd-plus.webp. Bagian dalam logo putih, jadi tetap terbaca di mode gelap.
// Logo berbentuk lonjong tegak: atur tingginya (mis. h-9) dan biarkan lebarnya otomatis
export default function AppLogoIcon({ className, alt = 'Logo SD Plus Gembala Baik', ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/images/logo-sd-plus.webp" alt={alt} className={['w-auto object-contain', className].filter(Boolean).join(' ')} {...props} />;
}
