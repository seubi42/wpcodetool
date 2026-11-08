<?php

namespace Smbb\WpCodeTool\Tests\Unit;

use Smbb\WpCodeTool\Security\Totp;
use Smbb\WpCodeTool\Tests\Support\TestCase;

/**
 * Codes d'application d'authentification : vecteurs officiels de la RFC 6238 (SHA-1).
 */
final class TotpTest extends TestCase
{
    // Clé de la RFC : « 12345678901234567890 » en ASCII.
    private function secret()
    {
        return Totp::base32Encode('12345678901234567890');
    }

    public function testBase32RoundTrip()
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $this->secret());
        $this->assertSame('12345678901234567890', Totp::base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
        $this->assertSame('', Totp::base32Decode('not base32 !'));
    }

    public function testRfc6238Vectors()
    {
        // 6 derniers chiffres des codes à 8 chiffres de la RFC 6238, annexe B.
        $vectors = array(
            59 => '287082',
            1111111109 => '081804',
            1111111111 => '050471',
            1234567890 => '005924',
            2000000000 => '279037',
            20000000000 => '353130',
        );

        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::code($this->secret(), Totp::counter($time)), 'T=' . $time);
        }
    }

    public function testMatchToleratesOneStepAndRefusesReplay()
    {
        $secret = $this->secret();
        $time = 1234567890;
        $now = Totp::counter($time);

        $this->assertSame($now, Totp::match($secret, '005924', $time));
        $this->assertSame($now, Totp::match($secret, '005 924', $time));
        $this->assertSame($now, Totp::match($secret, '005924', $time + 30)); // code de la tranche précédente
        $this->assertFalse(Totp::match($secret, '005924', $time + 90));      // trop vieux
        $this->assertFalse(Totp::match($secret, '005924', $time, $now));     // déjà utilisé
        $this->assertFalse(Totp::match($secret, '12345', $time));
    }

    public function testUriIsReadableByApps()
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'seb@exemple.fr', 'Mon Site: test');

        $this->assertSame('otpauth://totp/Mon%20Site%20%20test:seb%40exemple.fr?secret=JBSWY3DPEHPK3PXP&issuer=Mon%20Site%20%20test&algorithm=SHA1&digits=6&period=30', $uri);
        $this->assertSame(32, strlen(Totp::newSecret()));
    }
}
