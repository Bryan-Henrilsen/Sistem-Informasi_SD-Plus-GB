// Format tampilan berbahasa Indonesia untuk uang dan tanggal

// 150000 -> "Rp150.000"
export function rupiah(nilai: number): string {
    return 'Rp' + nilai.toLocaleString('id-ID');
}

// "2026-11-05" -> "5 Nov 2026"
export function tanggal(iso: string): string {
    return new Date(iso.slice(0, 10) + 'T00:00:00').toLocaleDateString(
        'id-ID',
        { day: 'numeric', month: 'short', year: 'numeric' },
    );
}

// "2026-11-05" -> "Kamis, 5 Nov 2026"
export function hariTanggal(iso: string): string {
    return new Date(iso.slice(0, 10) + 'T00:00:00').toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// ISO tanggal-jam -> "14 Nov 2026 15.30"
export function waktu(iso: string): string {
    return new Date(iso).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// "2026-11" atau "2026-11-01" -> "November 2026" (pendek: "Nov 26")
export function namaBulan(iso: string, pendek = false): string {
    return new Date(iso.slice(0, 7) + '-01T00:00:00').toLocaleDateString(
        'id-ID',
        pendek
            ? { month: 'short', year: '2-digit' }
            : { month: 'long', year: 'numeric' },
    )
}
