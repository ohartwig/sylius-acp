<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Controller;

use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAcp\Cart\CartPresenter;
use Koh\SyliusAcp\Cart\CartRequestParser;
use Koh\SyliusAcp\Http\AcpError;
use Koh\SyliusAcp\Http\BearerTokenAuthenticator;
use Koh\SyliusAcp\Http\IdempotencyStore;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The ACP cart service (rfc.cart.md, openapi.cart.yaml of ACP 2026-04-17):
 * `POST /carts`, `GET /carts/{id}`, `PUT /carts/{id}`, `POST /carts/{id}/cancel`
 * under `api_base`.
 *
 * Why carts and not checkout: a checkout session's `complete` takes
 * `payment_data` for a payment handler and MUST create an order, and every
 * handler ACP defines runs through `delegate_payment`. This shop has no payment
 * handler for agents, so it cannot honestly offer checkout. Carts carry no
 * payment and cannot be completed; the RFC's `continue_url` hands the cart to
 * the buyer, who checks out in the shop — where the shop's age confirmation,
 * address and payment steps apply as for any other customer.
 *
 * Every call needs a bearer key and `API-Version`; every POST an
 * `Idempotency-Key`, which PUT honours when sent.
 */
final class CartController
{
    /**
     * @param list<string> $supportedVersions oldest first, as in the discovery document
     */
    public function __construct(
        private readonly AgentCartStore $store,
        private readonly CartPresenter $presenter,
        private readonly CartRequestParser $parser,
        private readonly BearerTokenAuthenticator $authenticator,
        private readonly IdempotencyStore $idempotency,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly bool $enabled,
        private readonly array $supportedVersions,
        private readonly ?RateLimiterFactory $limiter = null,
    ) {
    }

    public function create(Request $request): Response
    {
        return $this->handle($request, true, function (AgentContext $context, string $identity) use ($request): JsonResponse {
            $cartRequest = $this->parser->parse($request->getContent());
            $write = $this->store->create($context->channel, $this->locale($cartRequest->locale, $request, $context), $cartRequest, $identity);

            return $this->cart($write->cart, $context, $write->messages, $cartRequest->buyerGiven, 201);
        });
    }

    public function retrieve(Request $request, string $id): Response
    {
        return $this->handle($request, false, fn (AgentContext $context, string $identity): JsonResponse => $this->cart($this->find($id, $context, $identity), $context, [], false));
    }

    public function update(Request $request, string $id): Response
    {
        return $this->handle($request, null !== $request->headers->get('Idempotency-Key'), function (AgentContext $context, string $identity) use ($request, $id): JsonResponse {
            $cart = $this->find($id, $context, $identity);
            $cartRequest = $this->parser->parse($request->getContent());
            $write = $this->store->replace($cart, $cartRequest);

            return $this->cart($write->cart, $context, $write->messages, $cartRequest->buyerGiven);
        });
    }

    public function cancel(Request $request, string $id): Response
    {
        return $this->handle($request, true, function (AgentContext $context, string $identity) use ($id): JsonResponse {
            $cart = $this->find($id, $context, $identity);
            // The RFC wants the state before deletion.
            $response = $this->cart($cart, $context, [], false);
            $this->store->cancel($cart);

            return $response;
        });
    }

    /**
     * @param \Closure(AgentContext, string): JsonResponse $action
     */
    private function handle(Request $request, bool $idempotent, \Closure $action): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('This shop does not offer ACP carts.');
        }

        $key = null;
        $scope = '';
        try {
            $identity = $this->authenticator->authenticate($request);
            $this->checkVersion($request);
            $this->throttle($identity);

            if ($idempotent) {
                $key = $this->idempotencyKey($request);
                $scope = $identity.' '.$request->getPathInfo();
                $replay = $this->idempotency->begin($scope, $key, $request->getContent());
                if (null !== $replay) {
                    $response = new JsonResponse($replay['body'], $replay['status'], ['Idempotent-Replayed' => 'true'], true);

                    return $this->finish($request, $response, $key);
                }
            }

            $response = $action($this->contextFactory->create(), $identity);
        } catch (AcpError $error) {
            $response = new JsonResponse($error->toArray(), $error->status, $error->headers);
        } catch (\Throwable $e) {
            if (null !== $key) {
                $this->idempotency->release($scope, $key);
            }

            throw $e;
        }

        if (null !== $key) {
            $this->idempotency->complete($scope, $key, $request->getContent(), $response->getStatusCode(), (string) $response->getContent());
        }

        return $this->finish($request, $response, $key);
    }

    private function finish(Request $request, Response $response, ?string $key): Response
    {
        if (null !== $key) {
            $response->headers->set('Idempotency-Key', $key);
        }
        $requestId = $request->headers->get('Request-Id');
        if (null !== $requestId && '' !== $requestId) {
            $response->headers->set('Request-Id', $requestId);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function checkVersion(Request $request): void
    {
        $version = $request->headers->get('API-Version');
        if (null === $version || '' === $version) {
            throw new AcpError(400, AcpError::TYPE_INVALID_REQUEST, 'missing_api_version', 'The API-Version header is required.', supportedVersions: array_reverse($this->supportedVersions));
        }
        if (!\in_array($version, $this->supportedVersions, true)) {
            throw new AcpError(400, AcpError::TYPE_INVALID_REQUEST, 'unsupported_api_version', \sprintf('API version %s is not supported.', $version), supportedVersions: array_reverse($this->supportedVersions));
        }
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->headers->get('Idempotency-Key') ?? '';
        if ('' === $key) {
            throw AcpError::invalid('idempotency_key_required', 'Idempotency-Key header is required on all POST requests');
        }
        if (\strlen($key) > 255) {
            throw AcpError::invalid('invalid', 'Idempotency-Key must not exceed 255 characters.');
        }

        return $key;
    }

    private function throttle(string $identity): void
    {
        if (null === $this->limiter) {
            return;
        }

        $limit = $this->limiter->create($identity)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new AcpError(
            429,
            AcpError::TYPE_INVALID_REQUEST,
            'rate_limited',
            'Too many cart requests; retry later.',
            headers: ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
        );
    }

    /** A cart is visible only to the caller that created it. */
    private function find(string $id, AgentContext $context, string $identity): OrderInterface
    {
        return $this->store->find($id, $context->channel, $identity)
            ?? throw AcpError::invalid('not_found', 'Cart not found or has expired.', status: 404);
    }

    /**
     * The `locale` field first, then Accept-Language, then the channel default
     * — always one of the channel's locales.
     */
    private function locale(?string $requested, Request $request, AgentContext $context): string
    {
        $available = $context->localeCodes;
        $candidates = [];
        if (null !== $requested) {
            $candidates[] = $requested;
        }
        foreach ($request->getLanguages() as $language) {
            $candidates[] = $language;
        }

        foreach ($candidates as $candidate) {
            $code = str_replace('-', '_', $candidate);
            foreach ($available as $locale) {
                if (0 === strcasecmp($locale, $code)) {
                    return $locale;
                }
            }
            foreach ($available as $locale) {
                if (0 === strcasecmp(explode('_', $locale)[0], explode('_', $code)[0])) {
                    return $locale;
                }
            }
        }

        return $context->localeCode;
    }

    /**
     * @param list<array<string, string>> $messages
     */
    private function cart(OrderInterface $cart, AgentContext $context, array $messages, bool $buyerGiven, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->presenter->present($cart, $context, $messages, $buyerGiven), $status);
    }
}
