import { Link } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import type { Paginasi as PaginasiData } from "@/types/les-ekskul";

// Tombol Sebelumnya/Berikutnya di bawah tabel; filter ikut terbawa karena withQueryString() di controller
export function Paginasi<T>({ data }: { data: PaginasiData<T> }) {
    if (data.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
                {data.from}-{data.to} dari {data.total} &middot; halaman{' '}
                {data.current_page}/{data.last_page}
            </span>
            <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={!data.prev_page_url} asChild={!!data.prev_page_url}>
                    {data.prev_page_url ? (
                        <Link href={data.prev_page_url} preserveScroll>
                            Sebelumnya
                        </Link>
                    ) : (
                        <span>Sebelumnya</span>
                    )}
                </Button>
                <Button variant="outline" size="sm" disabled={!data.next_page_url} asChild={!!data.next_page_url}>
                    {data.next_page_url ? (
                        <Link href={data.next_page_url} preserveScroll>
                            Berikutnya
                        </Link>
                    ) : (
                        <span>Berikutnya</span>
                    )}
                </Button>
            </div>
        </div>
    );
}
