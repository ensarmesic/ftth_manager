<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_it_generates_and_verifies_standard_totp_codes(): void
    {
        $totp = new TotpService;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $totp->currentCode($secret, 59));
        $this->assertTrue($totp->verify($secret, '287082', 59));
        $this->assertFalse($totp->verify($secret, '000000', 59));
    }

    public function test_generated_secrets_are_base32_and_create_a_valid_uri(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertStringContainsString('secret='.$secret, $totp->uri($secret, 'admin', 'FTTH Manager'));
        $this->assertTrue($totp->verify($secret, $totp->currentCode($secret)));
    }
}
