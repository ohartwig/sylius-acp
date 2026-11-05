<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * `Authorization: Bearer <token>`, which ACP requires on every call.
 *
 * ACP leaves token issuance to the seller; agent platforms receive an API key
 * out of band. The installation configures only the SHA-256 digests of the keys
 * it issued (`koh_acp.carts.bearer_token_hashes`), so no key sits in the
 * configuration, and revoking one is removing its digest.
 */
final class BearerTokenAuthenticator
{
    /**
     * Anything that is not a SHA-256 hex digest can never equal one, so a
     * malformed entry simply matches no key.
     *
     * @var list<string>
     */
    private readonly array $digests;

    /**
     * @param string $tokenHashes SHA-256 hex digests, separated by commas or whitespace
     */
    public function __construct(string $tokenHashes = '')
    {
        $digests = preg_split('/[\s,]+/', strtolower($tokenHashes), -1, \PREG_SPLIT_NO_EMPTY);
        $this->digests = false === $digests ? [] : $digests;
    }

    /**
     * @return string a stable, non-secret identity for the caller (scopes
     *                idempotency keys and the rate limit)
     *
     * @throws AcpError 401 when the token is missing or unknown
     */
    public function authenticate(Request $request): string
    {
        $header = $request->headers->get('Authorization') ?? '';
        if (1 !== preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $match)) {
            throw $this->unauthorized();
        }

        $presented = hash('sha256', $match[1]);
        foreach ($this->digests as $digest) {
            if (hash_equals($digest, $presented)) {
                return 'key_'.substr($digest, 0, 16);
            }
        }

        throw $this->unauthorized();
    }

    private function unauthorized(): AcpError
    {
        return new AcpError(
            401,
            AcpError::TYPE_INVALID_REQUEST,
            'unauthorized',
            'Invalid or missing Authorization header.',
            headers: ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
