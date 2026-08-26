import { Form, Head, setLayoutProps } from '@inertiajs/react';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { useT } from '@/lib/i18n';
import {
    authHelperTextClass,
    authInputClass,
    authLinkClass,
    authOtpSlotClass,
    authPrimaryButtonClass,
} from '@/pages/auth/styles';
import { store } from '@/routes/two-factor/login';

export default function TwoFactorChallenge() {
    const { t } = useT();
    const [showRecoveryInput, setShowRecoveryInput] = useState<boolean>(false);
    const [code, setCode] = useState<string>('');

    const authConfigContent = useMemo<{
        title: string;
        description: string;
        toggleText: string;
    }>(() => {
        if (showRecoveryInput) {
            return {
                title: t('Recovery code'),
                description: t(
                    'Please confirm access to your account by entering one of your emergency recovery codes.',
                ),
                toggleText: t('login using an authentication code'),
            };
        }

        return {
            title: t('Authentication code'),
            description: t(
                'Enter the authentication code provided by your authenticator application.',
            ),
            toggleText: t('login using a recovery code'),
        };
    }, [showRecoveryInput, t]);

    setLayoutProps({
        title: authConfigContent.title,
        description: authConfigContent.description,
    });

    const toggleRecoveryMode = (clearErrors: () => void): void => {
        setShowRecoveryInput(!showRecoveryInput);
        clearErrors();
        setCode('');
    };

    return (
        <>
            <Head title={t('Two-factor authentication')} />

            <div className="space-y-6">
                <Form
                    {...store.form()}
                    className="space-y-5"
                    resetOnError
                    resetOnSuccess={!showRecoveryInput}
                >
                    {({ errors, processing, clearErrors }) => (
                        <>
                            {showRecoveryInput ? (
                                <>
                                    <Input
                                        name="recovery_code"
                                        type="text"
                                        placeholder={t('Enter recovery code')}
                                        className={`${authInputClass} text-center font-mono tracking-[0.25em]`}
                                        autoFocus={showRecoveryInput}
                                        required
                                    />
                                    <InputError
                                        message={errors.recovery_code}
                                    />
                                </>
                            ) : (
                                <div className="flex flex-col items-center justify-center space-y-4 rounded-[20px] border border-[#e1dacd] bg-[#f7f2ea] p-5 text-center">
                                    <div className="flex w-full items-center justify-center">
                                        <InputOTP
                                            name="code"
                                            maxLength={OTP_MAX_LENGTH}
                                            value={code}
                                            onChange={(value) => setCode(value)}
                                            disabled={processing}
                                            pattern={REGEXP_ONLY_DIGITS}
                                            autoFocus
                                            containerClassName="justify-center gap-2.5"
                                        >
                                            <InputOTPGroup className="gap-2.5">
                                                {Array.from(
                                                    { length: OTP_MAX_LENGTH },
                                                    (_, index) => (
                                                        <InputOTPSlot
                                                            key={index}
                                                            index={index}
                                                            className={
                                                                authOtpSlotClass
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </InputOTPGroup>
                                        </InputOTP>
                                    </div>
                                    <InputError message={errors.code} />
                                </div>
                            )}

                            <Button
                                type="submit"
                                className={`${authPrimaryButtonClass} w-full`}
                                disabled={processing}
                            >
                                {t('Continue')}
                            </Button>

                            <div
                                className={`text-center ${authHelperTextClass}`}
                            >
                                <span>{t('or you can')} </span>
                                <button
                                    type="button"
                                    className={`cursor-pointer ${authLinkClass}`}
                                    onClick={() =>
                                        toggleRecoveryMode(clearErrors)
                                    }
                                >
                                    {authConfigContent.toggleText}
                                </button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
