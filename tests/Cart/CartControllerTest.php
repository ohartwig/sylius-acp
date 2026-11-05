<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests\Cart;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAcp\Cart\AgentCartStore;
use Koh\SyliusAcp\Cart\CartPresenter;
use Koh\SyliusAcp\Cart\CartRequestParser;
use Koh\SyliusAcp\Controller\CartController;
use Koh\SyliusAcp\Http\BearerTokenAuthenticator;
use Koh\SyliusAcp\Http\IdempotencyStore;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class CartControllerTest extends TestCase
{
    use CartFixtures;

    private const KEY = 'platform-key';

    public function testCreateAnswers201WithTheCartAndEchoesTheHeaders(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getCurrencyCode')->willReturn('EUR');
        $cart->method('getTokenValue')->willReturn('acp_x');
        $cart->expects(self::once())->method('setLocaleCode')->with('de_DE');

        $response = $this->controller($this->store(['A' => $this->variant('A')], $cart))
            ->create($this->request('POST', '{"line_items":[{"id":"A"}]}', ['Request-Id' => 'r1']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('k1', $response->headers->get('Idempotency-Key'));
        self::assertSame('r1', $response->headers->get('Request-Id'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('acp_x', $this->json($response)['id']);
    }

    public function testAnEmptyRequestIdIsNotEchoed(): void
    {
        $response = $this->controller($this->store())->retrieve($this->request('GET', '', ['Request-Id' => '']), 'acp_x');

        self::assertFalse($response->headers->has('Request-Id'));
    }

    public function testCancelNeedsAnIdempotencyKey(): void
    {
        self::assertSame('idempotency_key_required', $this->json($this->controller($this->store())->cancel($this->request('POST', '', ['Idempotency-Key' => null]), 'acp_x'))['code']);
    }

    public function testIdempotencyKeysAreScopedToCallerAndPath(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::exactly(2))->method('flush');
        $controller = $this->controller($this->store(['A' => $this->variant('A')], manager: $manager), keys: [self::KEY, 'other-key']);

        self::assertSame(201, $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}'))->getStatusCode());
        $other = $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}', ['Authorization' => 'Bearer other-key']));
        self::assertSame(201, $other->getStatusCode());
        self::assertFalse($other->headers->has('Idempotent-Replayed'));
        self::assertSame(404, $controller->cancel($this->request('POST', '', [], '/api/agent/carts/acp_x/cancel'), 'acp_x')->getStatusCode(), 'same key, other path: no conflict');
    }

    public function testARetriedCreateIsReplayedNotRepeated(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('flush');
        $controller = $this->controller($this->store(['A' => $this->variant('A')], manager: $manager));

        $first = $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}'));
        $second = $controller->create($this->request('POST', '{ "line_items": [ { "id": "A" } ] }'));

        self::assertSame(201, $second->getStatusCode());
        self::assertSame('true', $second->headers->get('Idempotent-Replayed'));
        self::assertSame('k1', $second->headers->get('Idempotency-Key'));
        self::assertSame($first->getContent(), $second->getContent());
        self::assertFalse($first->headers->has('Idempotent-Replayed'));
    }

    public function testErrorsAreStoredAgainstTheKeyToo(): void
    {
        $controller = $this->controller($this->store());

        self::assertSame(422, $controller->create($this->request('POST', '{"line_items":[{"id":"X"}]}'))->getStatusCode());
        self::assertSame('true', $controller->create($this->request('POST', '{"line_items":[{"id":"X"}]}'))->headers->get('Idempotent-Replayed'));
    }

    public function testAPostWithoutIdempotencyKeyIsRefused(): void
    {
        $response = $this->controller($this->store())->create($this->request('POST', '{}', ['Idempotency-Key' => null]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'type' => 'invalid_request',
            'code' => 'idempotency_key_required',
            'message' => 'Idempotency-Key header is required on all POST requests',
        ], $this->json($response));
        self::assertFalse($response->headers->has('Idempotency-Key'));
    }

    public function testAnOverlongIdempotencyKeyIsRefused(): void
    {
        $controller = $this->controller($this->store(['A' => $this->variant('A')]));

        self::assertSame(400, $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}', ['Idempotency-Key' => str_repeat('k', 256)]))->getStatusCode());
        self::assertSame(201, $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}', ['Idempotency-Key' => str_repeat('k', 255)]))->getStatusCode());
    }

    public function testEveryCallNeedsTheBearerKey(): void
    {
        $response = $this->controller($this->store())->retrieve($this->request('GET', '', ['Authorization' => 'Bearer wrong']), 'acp_x');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', $this->json($response)['code']);
    }

    public function testTheApiVersionHeaderIsRequiredAndChecked(): void
    {
        $controller = $this->controller($this->store());

        $missing = $controller->retrieve($this->request('GET', '', ['API-Version' => null]), 'acp_x');
        self::assertSame(400, $missing->getStatusCode());
        self::assertSame(['type' => 'invalid_request', 'code' => 'missing_api_version', 'message' => 'The API-Version header is required.', 'supported_versions' => ['2026-04-17']], $this->json($missing));

        $old = $controller->retrieve($this->request('GET', '', ['API-Version' => '2025-09-29']), 'acp_x');
        self::assertSame(400, $old->getStatusCode());
        self::assertSame('unsupported_api_version', $this->json($old)['code']);
        self::assertSame(['2026-04-17'], $this->json($old)['supported_versions']);
    }

    public function testSupportedVersionsAreListedNewestFirst(): void
    {
        $controller = $this->controller($this->store(), versions: ['2026-01-30', '2026-04-17']);

        self::assertSame(['2026-04-17', '2026-01-30'], $this->json($controller->retrieve($this->request('GET', '', ['API-Version' => '2025-01-01']), 'acp_x'))['supported_versions']);
        self::assertSame(['2026-04-17', '2026-01-30'], $this->json($controller->retrieve($this->request('GET', '', ['API-Version' => null]), 'acp_x'))['supported_versions']);
        self::assertSame(404, $controller->retrieve($this->request('GET', '', ['API-Version' => '2026-01-30']), 'acp_x')->getStatusCode());
    }

    public function testRetrieveReturnsTheCreatorsCart(): void
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findCartByTokenValueAndChannel')->willReturnCallback(fn (string $token) => $this->cart(token: $token));
        $store = $this->store(['A' => $this->variant('A')], orders: $orders);
        $id = $this->createdId($store);

        $mine = $this->controller($store)->retrieve($this->request('GET', ''), $id);
        self::assertSame(200, $mine->getStatusCode());
        self::assertSame($id, $this->json($mine)['id']);
        self::assertFalse($mine->headers->has('Idempotency-Key'));
        self::assertNotContains('$.buyer', array_column($this->json($mine)['messages'], 'param'));

        $theirs = $this->controller($store, keys: [self::KEY, 'other-key'])->retrieve($this->request('GET', '', ['Authorization' => 'Bearer other-key']), $id);
        self::assertSame(404, $theirs->getStatusCode());
        self::assertSame(['type' => 'invalid_request', 'code' => 'not_found', 'message' => 'Cart not found or has expired.'], $this->json($theirs));
    }

    public function testUpdateReplacesTheLinesWithOrWithoutAKey(): void
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $stored = $this->cart();
        $stored->method('getChannel')->willReturn($this->channel());
        $stored->expects(self::exactly(2))->method('clearItems');
        $orders->method('findCartByTokenValueAndChannel')->willReturn($stored);
        $store = $this->store(['A' => $this->variant('A')], orders: $orders);
        $id = $this->createdId($store);
        $controller = $this->controller($store);

        $withoutKey = $controller->update($this->request('PUT', '{"line_items":[{"id":"A","quantity":3}],"buyer":{"email":"b@example.com"}}', ['Idempotency-Key' => null]), $id);
        self::assertSame(200, $withoutKey->getStatusCode());
        self::assertFalse($withoutKey->headers->has('Idempotency-Key'));
        self::assertContains('$.buyer', array_column($this->json($withoutKey)['messages'], 'param'));

        $withKey = $controller->update($this->request('PUT', '{"line_items":[{"id":"A"}]}', ['Idempotency-Key' => 'u1']), $id);
        self::assertSame('u1', $withKey->headers->get('Idempotency-Key'));
        self::assertSame('true', $controller->update($this->request('PUT', '{"line_items":[{"id":"A"}]}', ['Idempotency-Key' => 'u1']), $id)->headers->get('Idempotent-Replayed'));
    }

    public function testCancelReturnsTheLastStateAndRemovesTheCart(): void
    {
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findCartByTokenValueAndChannel')->willReturnCallback(fn (string $token) => $this->cart(token: $token));
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('remove');
        $store = $this->store(['A' => $this->variant('A')], orders: $orders, manager: $manager);
        $id = $this->createdId($store);

        $response = $this->controller($store)->cancel($this->request('POST', ''), $id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($id, $this->json($response)['id']);
    }

    public function testCancellingAnUnknownCartIs404(): void
    {
        self::assertSame(404, $this->controller($this->store())->cancel($this->request('POST', ''), 'acp_x')->getStatusCode());
    }

    public function testTheRateLimitAppliesPerKey(): void
    {
        $limiter = new RateLimiterFactory(['id' => 't', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());
        $controller = $this->controller($this->store(), limiter: $limiter);

        self::assertSame(404, $controller->retrieve($this->request('GET', ''), 'acp_x')->getStatusCode());
        $limited = $controller->retrieve($this->request('GET', ''), 'acp_x');
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('rate_limited', $this->json($limited)['code']);
        $retryAfter = $limited->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertGreaterThanOrEqual(58, (int) $retryAfter);
        self::assertLessThanOrEqual(61, (int) $retryAfter);
    }

    public function testTheLocaleComesFromTheBodyThenAcceptLanguageThenTheChannel(): void
    {
        self::assertSame('en_GB', $this->createdLocale('{"line_items":[{"id":"A"}],"locale":"en-GB"}', 'de-DE'));
        self::assertSame('en_GB', $this->createdLocale('{"line_items":[{"id":"A"}],"locale":"en-US"}', null), 'language match');
        self::assertSame('en_GB', $this->createdLocale('{"line_items":[{"id":"A"}]}', 'fr-FR, en;q=0.8'));
        self::assertSame('de_DE', $this->createdLocale('{"line_items":[{"id":"A"}],"locale":"fr-FR"}', null));
        self::assertSame('de_DE', $this->createdLocale('{"line_items":[{"id":"A"}]}', 'de-AT'));

        // An exact match beats a language match listed earlier.
        $context = new \Koh\SyliusAgentContracts\Context\AgentContext($this->channel(), 'https://shop.example.com', 'de_DE', ['de_DE', 'en_US', 'en_GB']);
        self::assertSame('en_GB', $this->createdLocale('{"line_items":[{"id":"A"}],"locale":"en-GB"}', null, $context));
        self::assertSame('en_US', $this->createdLocale('{"line_items":[{"id":"A"}],"locale":"en-IE"}', null, $context));
    }

    public function testAFailingWriteReleasesItsKey(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('flush')->willThrowException(new \RuntimeException('database down'));
        $controller = $this->controller($this->store(['A' => $this->variant('A')], manager: $manager));

        try {
            $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}'));
            self::fail('Expected the failure to surface');
        } catch (\RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
        }

        $this->expectExceptionMessage('database down');
        $controller->create($this->request('POST', '{"line_items":[{"id":"A"}]}'));
    }

    public function testWithCartsOffEveryRouteIs404(): void
    {
        $controller = $this->controller($this->store(), enabled: false);

        foreach ([
            fn () => $controller->create($this->request('POST', '{}')),
            fn () => $controller->retrieve($this->request('GET', ''), 'x'),
            fn () => $controller->update($this->request('PUT', '{}'), 'x'),
            fn () => $controller->cancel($this->request('POST', ''), 'x'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a 404');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function createdLocale(string $body, ?string $acceptLanguage, ?\Koh\SyliusAgentContracts\Context\AgentContext $context = null): string
    {
        $locale = '';
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('setLocaleCode')->willReturnCallback(function (string $value) use (&$locale): void {
            $locale = $value;
        });
        $this->controller($this->store(['A' => $this->variant('A')], $cart), context: $context)
            ->create($this->request('POST', $body, ['Accept-Language' => $acceptLanguage, 'Idempotency-Key' => bin2hex(random_bytes(4))]));

        return $locale;
    }

    private function createdId(AgentCartStore $store): string
    {
        $token = '';
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('setTokenValue')->willReturnCallback(function (string $value) use (&$token): void {
            $token = $value;
        });
        $creating = $this->store(['A' => $this->variant('A')], $cart);
        $creating->create($this->channel(), 'de_DE', (new CartRequestParser())->parse('{"line_items":[{"id":"A"}]}'), 'key_'.substr(hash('sha256', self::KEY), 0, 16));

        return $token;
    }

    /**
     * @param list<string> $keys
     * @param list<string> $versions
     */
    private function controller(
        AgentCartStore $store,
        bool $enabled = true,
        array $keys = [self::KEY],
        array $versions = ['2026-04-17'],
        ?RateLimiterFactory $limiter = null,
        ?\Koh\SyliusAgentContracts\Context\AgentContext $context = null,
    ): CartController {
        $contexts = $this->createMock(AgentContextFactoryInterface::class);
        $contexts->method('create')->willReturn($context ?? $this->context());

        return new CartController(
            $store,
            new CartPresenter($store),
            new CartRequestParser(),
            new BearerTokenAuthenticator(implode(',', array_map(static fn (string $k): string => hash('sha256', $k), $keys))),
            new IdempotencyStore(new ArrayAdapter()),
            $contexts,
            $enabled,
            $versions,
            $limiter,
        );
    }

    /**
     * @param array<string, string|null> $headers null removes a default
     */
    private function request(string $method, string $body, array $headers = [], string $path = '/api/agent/carts'): Request
    {
        $request = Request::create($path, $method, content: $body);
        $headers += ['Authorization' => 'Bearer '.self::KEY, 'API-Version' => '2026-04-17', 'Idempotency-Key' => 'k1', 'Content-Type' => 'application/json', 'Accept-Language' => null];
        foreach ($headers as $name => $value) {
            if (null === $value) {
                $request->headers->remove($name);
            } else {
                $request->headers->set($name, $value);
            }
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
