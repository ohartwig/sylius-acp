<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Http;

/**
 * A protocol-level ACP error: the request cannot produce a resource at all.
 *
 * Rendered as the flat `Error` object of ACP 2026-04-17
 * (schema.agentic_checkout.json#/$defs/Error). Problems the agent can work
 * around while the cart still exists travel as messages on the cart instead.
 */
final class AcpError extends \RuntimeException
{
    public const TYPE_INVALID_REQUEST = 'invalid_request';
    public const TYPE_PROCESSING_ERROR = 'processing_error';
    public const TYPE_SERVICE_UNAVAILABLE = 'service_unavailable';

    /**
     * @param array<string, string> $headers
     * @param list<string>|null     $supportedVersions only on version errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $param = null,
        public readonly array $headers = [],
        public readonly ?array $supportedVersions = null,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $code, string $message, ?string $param = null, int $status = 400): self
    {
        return new self($status, self::TYPE_INVALID_REQUEST, $code, $message, $param);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $error = [
            'type' => $this->type,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];
        if (null !== $this->param) {
            $error['param'] = $this->param;
        }
        if (null !== $this->supportedVersions) {
            $error['supported_versions'] = $this->supportedVersions;
        }

        return $error;
    }
}
