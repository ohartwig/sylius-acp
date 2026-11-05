<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Koh\SyliusAcp\Http\AcpError;
use Koh\SyliusAcp\Http\BearerTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BearerTokenAuthenticatorTest extends TestCase
{
    public function testAnIssuedKeyAuthenticatesAsAStableNonSecretIdentity(): void
    {
        $digest = hash('sha256', 'secret-one');
        $authenticator = new BearerTokenAuthenticator(hash('sha256', 'other').", \n".strtoupper($digest));

        self::assertSame('key_'.substr($digest, 0, 16), $authenticator->authenticate($this->request('Bearer secret-one')));
        self::assertSame('key_'.substr($digest, 0, 16), $authenticator->authenticate($this->request('bearer   secret-one ')));
    }

    public function testMalformedDigestsInTheConfigurationMatchNothing(): void
    {
        $authenticator = new BearerTokenAuthenticator('nonsense,'.hash('sha256', 'k').'0');

        $this->assertRejected($authenticator, 'Bearer k');
        $this->assertRejected($authenticator, 'Bearer nonsense');
    }

    public function testMissingWrongOrForeignSchemesAreRejected(): void
    {
        $authenticator = new BearerTokenAuthenticator(hash('sha256', 'k'));

        $this->assertRejected($authenticator, null);
        $this->assertRejected($authenticator, 'Bearer wrong');
        $this->assertRejected($authenticator, 'Basic k');
        $this->assertRejected($authenticator, 'Bearer');
        $this->assertRejected($authenticator, 'Bearer k extra');
        $this->assertRejected($authenticator, 'xBearer k');
    }

    public function testWithoutConfiguredKeysNobodyGetsIn(): void
    {
        $this->assertRejected(new BearerTokenAuthenticator(), 'Bearer anything');
    }

    private function assertRejected(BearerTokenAuthenticator $authenticator, ?string $header): void
    {
        try {
            $authenticator->authenticate($this->request($header));
            self::fail('Expected a 401');
        } catch (AcpError $error) {
            self::assertSame(401, $error->status);
            self::assertSame('unauthorized', $error->errorCode);
            self::assertSame('invalid_request', $error->type);
            self::assertSame(['WWW-Authenticate' => 'Bearer'], $error->headers);
        }
    }

    private function request(?string $authorization): Request
    {
        $request = new Request();
        if (null !== $authorization) {
            $request->headers->set('Authorization', $authorization);
        }

        return $request;
    }
}
