<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticator
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT)
        );
    }

    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || $code === '') {
            return false;
        }

        // verifyKey answers with the matching timestamp, not a boolean, and false on no match.
        return $this->google2fa->verifyKey($secret, $code) !== false;
    }

    /**
     * Recovery codes are single use, so a successful match removes it before the caller can
     * act on the result.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalised = Str::lower(trim($code));

        if ($normalised === '' || ! in_array($normalised, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$normalised])),
        ])->save();

        return true;
    }

    public function qrCodeSvg(User $user): string
    {
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            return '';
        }

        $appName = config('app.name');
        $url = $this->google2fa->getQRCodeUrl(
            is_string($appName) ? $appName : 'Stocks',
            $user->email,
            $secret
        );

        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd));

        return $writer->writeString($url);
    }
}
