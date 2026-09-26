<?php

namespace App\OnlyOffice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tokens exchanged with ONLYOFFICE Docs (HS256 with the secret shared with it, its JWT_SECRET): requests to its API,
 * editor configurations, and the callbacks it sends.
 */
final class OnlyOfficeJwt
{
    public function __construct(
        #[Autowire(env: 'ONLYOFFICE_JWT_SECRET')] #[\SensitiveParameter] private readonly string $secret,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->secret;
    }

    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        $segments = [self::encode(['alg' => 'HS256', 'typ' => 'JWT']), self::encode($payload)];

        return implode('.', $segments).'.'.self::base64(hash_hmac('sha256', implode('.', $segments), $this->secret, true));
    }

    /**
     * @return array<string, mixed> the payload
     *
     * @throws \UnexpectedValueException invalid or expired token
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts) || !$this->isEnabled()) {
            throw new \UnexpectedValueException('Malformed token.');
        }
        $header = json_decode(self::decode($parts[0]), true);
        if (!\is_array($header) || 'HS256' !== ($header['alg'] ?? null)) {
            throw new \UnexpectedValueException('Unsupported token algorithm.');
        }
        $expected = self::base64(hash_hmac('sha256', $parts[0].'.'.$parts[1], $this->secret, true));
        if (!hash_equals($expected, $parts[2])) {
            throw new \UnexpectedValueException('Invalid token signature.');
        }
        $payload = json_decode(self::decode($parts[1]), true);
        if (!\is_array($payload)) {
            throw new \UnexpectedValueException('Malformed token payload.');
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new \UnexpectedValueException('Expired token.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return self::base64(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private static function base64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
