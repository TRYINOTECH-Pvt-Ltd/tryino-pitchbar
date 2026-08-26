import { Form, Head } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import {
    authCheckboxClass,
    authHelperTextClass,
    authInputClass,
    authLabelClass,
    authLinkClass,
    authNoticeClass,
    authPrimaryButtonClass,
} from '@/pages/auth/styles';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type DemoCredential = {
    role: string;
    email: string;
    password: string;
    description?: string;
};

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    demoCredentials?: DemoCredential[];
};

/**
 * Click-to-fill helper. Walks the rendered form to the email + password
 * inputs by name attribute and sets their values via the native input
 * setter so React/Preact sees the change, then dispatches an `input`
 * event so Inertia's <Form> picks up the new value on submit.
 */
function fillCredentials(email: string, password: string): void {
    const setValue = (name: string, value: string): void => {
        const el = document.querySelector<HTMLInputElement>(
            `input[name="${name}"]`,
        );

        if (!el) {
            return;
        }

        const setter = Object.getOwnPropertyDescriptor(
            window.HTMLInputElement.prototype,
            'value',
        )?.set;

        if (setter) {
            setter.call(el, value);
        } else {
            el.value = value;
        }

        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    setValue('email', email);
    setValue('password', password);
    document.querySelector<HTMLInputElement>('input[name="email"]')?.focus();
}

export default function Login({
    status,
    canResetPassword,
    canRegister,
    demoCredentials = [],
}: Props) {
    const { t } = useT();

    return (
        <>
            <Head title={t('Log in')} />

            {demoCredentials.length > 0 && (
                // Solid colours throughout — the auth layout uses a
                // hardcoded cream palette and ignores the `dark` class,
                // so opacity-tinted backgrounds (bg-white/80) bleed
                // through and wash out the text. Stick to fully-opaque
                // amber-tinted backgrounds + slate text for guaranteed
                // contrast.
                <div className="mb-6 grid gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-start">
                    <div className="flex items-center gap-2 text-[11px] font-semibold tracking-[0.16em] text-amber-800 uppercase">
                        <Sparkles className="size-3.5" />
                        {t('Demo credentials — click to autofill')}
                    </div>
                    <div className="grid gap-1.5">
                        {demoCredentials.map((c) => (
                            <button
                                key={c.email}
                                type="button"
                                onClick={() =>
                                    fillCredentials(c.email, c.password)
                                }
                                className="group flex items-start justify-between gap-3 rounded-md border border-amber-300 bg-white px-3 py-2 text-start shadow-sm transition hover:border-amber-500 hover:bg-amber-50"
                            >
                                {/* Left: role + description */}
                                <div className="min-w-0 flex-1">
                                    <p className="text-[13px] font-semibold text-slate-900">
                                        {c.role}
                                    </p>
                                    {c.description && (
                                        <p className="mt-0.5 text-[11px] leading-4 text-slate-600">
                                            {c.description}
                                        </p>
                                    )}
                                </div>

                                {/* Right: stacked credentials in mono */}
                                <div className="flex flex-col items-end gap-0.5 text-end">
                                    <span className="font-mono text-[11px] font-medium text-slate-800">
                                        {c.email}
                                    </span>
                                    <span className="font-mono text-[11px] text-slate-500">
                                        <span className="text-slate-400">
                                            pw:&nbsp;
                                        </span>
                                        {c.password}
                                    </span>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="grid gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {status && (
                            <div className={authNoticeClass}>{status}</div>
                        )}

                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="email"
                                    className={authLabelClass}
                                >
                                    {t('Email address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    className={authInputClass}
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label
                                        htmlFor="password"
                                        className={authLabelClass}
                                    >
                                        {t('Password')}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className={`${authLinkClass} ms-auto text-sm`}
                                            tabIndex={5}
                                        >
                                            {t('Forgot password?')}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={t('Password')}
                                    className={authInputClass}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                    className={authCheckboxClass}
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-sm font-medium text-[#4e564d]"
                                >
                                    {t('Remember me')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className={`${authPrimaryButtonClass} mt-1 w-full`}
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('Log in')}
                            </Button>
                        </div>

                        {canRegister && (
                            <div
                                className={`text-center ${authHelperTextClass}`}
                            >
                                {t("Don't have an account?")}{' '}
                                <TextLink
                                    href={register()}
                                    tabIndex={5}
                                    className={authLinkClass}
                                >
                                    {t('Sign up')}
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
