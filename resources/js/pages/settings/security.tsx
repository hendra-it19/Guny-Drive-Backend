import { Form, Head } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useRef } from 'react';
import VaultPassphraseController from '@/actions/App/Http/Controllers/Dashboard/VaultPassphraseController';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { edit } from '@/routes/security';

// oxfmt-ignore
type Props = {
    passwordRules: string;
    vaultConfigured?: boolean;
    vaultUpdatedAt?: string | null;
} & ManagePasskeysProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Update password"
                    description="Ensure your account is using a long, random password to stay secure"
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Current password
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Current password"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">New password</Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="New password"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                    passwordrules={props.passwordRules}
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <div className="mt-10 space-y-6 border-t pt-8">
                <div className="flex items-start gap-3">
                    <span className="mt-0.5 flex size-9 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                        <Lock className="size-4" />
                    </span>
                    <Heading
                        variant="small"
                        title="Frasa Sandi Folder Privat"
                        description={
                            props.vaultConfigured
                                ? `Sudah aktif · Diperbarui ${formatDateTime(props.vaultUpdatedAt)}. Mengubah frasa sandi langsung mengunci semua sesi privat di perangkat.`
                                : 'Belum diatur. Tetapkan frasa sandi terpisah untuk membuka Folder Privat dari ponsel.'
                        }
                    />
                </div>

                <Form
                    {...VaultPassphraseController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    resetOnError={['passphrase', 'passphrase_confirmation']}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="vault_passphrase">
                                    Frasa sandi baru (minimal 6 karakter)
                                </Label>
                                <PasswordInput
                                    id="vault_passphrase"
                                    name="passphrase"
                                    placeholder="Masukkan frasa sandi Folder Privat"
                                />
                                <InputError message={errors.passphrase} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="vault_passphrase_confirmation">
                                    Konfirmasi frasa sandi
                                </Label>
                                <PasswordInput
                                    id="vault_passphrase_confirmation"
                                    name="passphrase_confirmation"
                                    placeholder="Ulangi frasa sandi"
                                />
                                <InputError
                                    message={errors.passphrase_confirmation}
                                />
                            </div>

                            <Button disabled={processing}>
                                {props.vaultConfigured
                                    ? 'Perbarui Frasa Sandi'
                                    : 'Aktifkan Folder Privat'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
