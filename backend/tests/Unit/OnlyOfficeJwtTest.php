<?php

namespace App\Tests\Unit;

use App\OnlyOffice\OnlyOfficeJwt;
use PHPUnit\Framework\TestCase;

final class OnlyOfficeJwtTest extends TestCase
{
    public function testSignAndVerify(): void
    {
        $jwt = new OnlyOfficeJwt('a-secret-of-at-least-32-characters!');
        $token = $jwt->sign(['key' => 'k', 'status' => 2]);

        self::assertSame(['key' => 'k', 'status' => 2], $jwt->verify($token));
    }

    public function testRefusesAnotherSecretATamperedOrExpiredToken(): void
    {
        $jwt = new OnlyOfficeJwt('a-secret-of-at-least-32-characters!');
        foreach ([
            (new OnlyOfficeJwt('another-secret-of-32-characters-xx'))->sign(['a' => 1]),
            preg_replace('/\.[^.]+\./', '.'.rtrim(strtr(base64_encode('{"a":2}'), '+/', '-_'), '=').'.', $jwt->sign(['a' => 1])),
            $jwt->sign(['exp' => time() - 10]),
            'not-a-token',
        ] as $token) {
            try {
                $jwt->verify((string) $token);
                self::fail('Accepted: '.$token);
            } catch (\UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
