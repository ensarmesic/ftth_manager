<?php

namespace App\Services;

use Illuminate\Support\Str;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        return $this->codeAt($secret, intdiv($timestamp ?? time(), 30));
    }

    public function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode(Str::upper($secret));
        $binaryCounter = pack('N2', intdiv($counter, 0x100000000), $counter % 0x100000000);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(rtrim($value, '=')) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                return '';
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
