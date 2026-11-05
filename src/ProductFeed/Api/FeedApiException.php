<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\ProductFeed\Api;

/**
 * An agent-hosted Feed API refused a request, or could not be reached.
 *
 * Carries ACP's flat error shape (`type`, `code`, `message`, `param`) when the
 * agent answered with one; otherwise `type` is `transport_error` or
 * `unexpected_response` and `code` names what went wrong.
 */
final class FeedApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $type,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $param = null,
        public readonly ?int $status = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function describe(): string
    {
        return \sprintf(
            '%s%s/%s: %s%s',
            null === $this->status ? '' : 'HTTP '.$this->status.' ',
            $this->type,
            $this->errorCode,
            $this->getMessage(),
            null === $this->param ? '' : ' (param: '.$this->param.')',
        );
    }
}
