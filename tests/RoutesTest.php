<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusAcp\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * The cart paths are what `api_base_url` plus the ACP OpenAPI paths resolve to,
 * and continue_url is built from a fixed path in CartPresenter. A typo in
 * either fails no request of ours — it fails the agent's.
 */
final class RoutesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function calls(): iterable
    {
        $id = 'acp_'.str_repeat('a', 32).'_'.str_repeat('b', 16);

        yield 'create' => ['POST', '/api/agent/carts', 'koh_acp_cart_create'];
        yield 'retrieve' => ['GET', '/api/agent/carts/'.$id, 'koh_acp_cart_retrieve'];
        yield 'update' => ['PUT', '/api/agent/carts/'.$id, 'koh_acp_cart_update'];
        yield 'cancel' => ['POST', '/api/agent/carts/'.$id.'/cancel', 'koh_acp_cart_cancel'];
        yield 'continue' => ['GET', '/acp-cart/'.$id, 'koh_acp_cart_continue'];
        yield 'discovery' => ['GET', '/.well-known/acp.json', 'koh_acp_discovery'];
        yield 'feed' => ['GET', '/api/agent/feed', 'koh_acp_product_feed'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('calls')]
    public function testEveryAcpCallReachesItsRoute(string $method, string $path, string $route): void
    {
        $matcher = new UrlMatcher($this->routes(), new RequestContext(method: $method));

        self::assertSame($route, $matcher->match($path)['_route']);
    }

    public function testIdsOutsideTheTokenAlphabetDoNotRoute(): void
    {
        $matcher = new UrlMatcher($this->routes(), new RequestContext(method: 'GET'));

        $this->expectException(\Symfony\Component\Routing\Exception\ResourceNotFoundException::class);
        $matcher->match('/acp-cart/..%2f');
    }

    private function routes(): RouteCollection
    {
        return (new PhpFileLoader(new FileLocator(\dirname(__DIR__).'/config')))->load('routes.php');
    }
}
