import { Form, Head, Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { logout } from '@/routes';

type Props = {
    profil: { name: string; no_hp: string | null; email: string | null };
    passwordRules: string;
};

// Akun orang tua: ubah nama/no HP/email dan password, lalu keluar (#36)
export default function AkunOrangTua({ profil, passwordRules }: Props) {
    return (
        <>
            <Head title="Akun" />

            <div className="flex flex-col gap-4">
                <h1 className="text-lg font-semibold">Akun</h1>

                <Card className="gap-3 py-5">
                    <CardHeader className="px-5">
                        <CardTitle className="text-base">Data diri</CardTitle>
                    </CardHeader>
                    <CardContent className="px-5">
                        <Form {...ProfileController.update.form()} options={{ preserveScroll: true }} className="flex flex-col gap-4">
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="name">Nama</Label>
                                        <Input id="name" name="name" defaultValue={profil.name} required autoComplete="name" className="h-11" />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="no_hp">No HP (untuk login)</Label>
                                        <Input id="no_hp" name="no_hp" type="tel" inputMode="tel" defaultValue={profil.no_hp ?? ''} required autoComplete="tel" className="h-11" />
                                        <InputError message={errors.no_hp} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="email">Email (opsional)</Label>
                                        <Input id="email" name="email" type="email" defaultValue={profil.email ?? ''} autoComplete="email" className="h-11" />
                                        <p className="text-xs text-muted-foreground">
                                            Dengan email, Anda bisa mengatur ulang password sendiri bila lupa. Tanpa email, hubungi TU.
                                        </p>
                                        <InputError message={errors.email} />
                                    </div>
                                    <Button disabled={processing} className="h-11">
                                        Simpan
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card className="gap-3 py-5">
                    <CardHeader className="px-5">
                        <CardTitle className="text-base">Ganti password</CardTitle>
                    </CardHeader>
                    <CardContent className="px-5">
                        <Form
                            {...SecurityController.update.form()}
                            options={{ preserveScroll: true }}
                            resetOnError={['password', 'password_confirmation', 'current_password']}
                            resetOnSuccess
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="current_password">Password sekarang</Label>
                                        <PasswordInput id="current_password" name="current_password" autoComplete="current-password" className="h-11" />
                                        <InputError message={errors.current_password} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="password">Password baru</Label>
                                        <PasswordInput id="password" name="password" autoComplete="new-password" passwordrules={passwordRules} className="h-11" />
                                        <InputError message={errors.password} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="password_confirmation">Ulangi password baru</Label>
                                        <PasswordInput id="password_confirmation" name="password_confirmation" autoComplete="new-password" className="h-11" />
                                        <InputError message={errors.password_confirmation} />
                                    </div>
                                    <Button disabled={processing} className="h-11">
                                        Ganti password
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Button variant="outline" className="h-11" asChild>
                    <Link href={logout()} as="button">
                        <LogOut className="size-4" />
                        Keluar
                    </Link>
                </Button>
            </div>
        </>
    );
}
