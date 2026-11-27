<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\SafeOutboundHttpClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundRedirectResolutionTest extends TestCase
{
    #[DataProvider('relativeReferences')]
    public function test_get_follows_the_exact_resolved_reference(string $base, string $location, string $expected): void
    {
        [$client, $transport, $resolver] = $this->client([$this->redirect($location), $this->ok()]);
        $validated = [];

        $response = $client->get((new Factory)->acceptJson(), $base, 128, 1, redirectValidator: function ($url) use (&$validated) {
            $validated[] = $url;
        });

        $this->assertSame('finished', $response->body());
        $this->assertSame([$base, $expected], array_column($transport->sent, 'url'));
        $this->assertSame(['GET', 'GET'], array_column($transport->sent, 'method'));
        $this->assertSame([$expected], $validated);
        $this->assertCount(2, $resolver->hosts, 'Every hop must resolve and validate its destination.');
    }

    public static function relativeReferences(): array
    {
        $base = 'https://example.com/articles/report?old=1';

        return [
            'query only keeps filename' => [$base, '?page=2', 'https://example.com/articles/report?page=2'],
            'empty query clears old query' => [$base, '?', 'https://example.com/articles/report?'],
            'query without base path' => ['https://example.com?old=1', '?page=2', 'https://example.com?page=2'],
            'parent segment' => [$base, '../next?page=2', 'https://example.com/next?page=2'],
            'nested dot segments' => [$base, './part/../next', 'https://example.com/articles/next'],
            'root reference dot segments' => [$base, '/root/../next', 'https://example.com/next'],
            'network reference' => [$base, '//cdn.example.com/a/../next?x=2', 'https://cdn.example.com/next?x=2'],
            'absolute reference' => [$base, 'https://cdn.example.com/a/../next', 'https://cdn.example.com/next'],
            'encoded segments are not dot segments' => [$base, '%2E%2E/%E6%96%87?x=%2F', 'https://example.com/articles/%2E%2E/%E6%96%87?x=%2F'],
            'UTF-8 parent path' => ['https://example.com/文章/报告?旧=1', '?页=2', 'https://example.com/文章/报告?页=2'],
            'UTF-8 relative path' => ['https://example.com/文章/报告', '新稿?词=测试', 'https://example.com/文章/新稿?词=测试'],
            'directory reference' => ['https://example.com/articles/', 'next', 'https://example.com/articles/next'],
            'nondefault port' => ['https://example.com:8443/a/report', '../next', 'https://example.com:8443/next'],
        ];
    }

    #[DataProvider('fragmentReferences')]
    public function test_fragment_is_resolved_correctly_and_still_rejected(string $location, string $expected): void
    {
        [$client, $transport] = $this->client([$this->redirect($location), $this->ok()]);
        $validated = [];

        $this->assertBlocked('fragment_forbidden', function () use ($client, &$validated) {
            $client->get(
                (new Factory)->acceptJson(), 'https://example.com/articles/report?old=1', 128, 1,
                redirectValidator: function ($url) use (&$validated) {
                    $validated[] = $url;
                },
            );
        });

        $this->assertSame([$expected], $validated);
        $this->assertCount(1, $transport->sent, 'A fragment must not reach transport.');
    }

    public static function fragmentReferences(): array
    {
        return [
            'fragment keeps path and query' => ['#section', 'https://example.com/articles/report?old=1#section'],
            'empty query and fragment' => ['?#', 'https://example.com/articles/report?#'],
        ];
    }

    #[DataProvider('blockedReferences')]
    public function test_redirect_does_not_bypass_destination_policy(string $location, string $reason): void
    {
        [$client, $transport] = $this->client([$this->redirect($location), $this->ok()]);

        $this->assertBlocked($reason, fn () => $client->get((new Factory)->acceptJson(), 'https://example.com/a/report', 128, 1));
        $this->assertCount(1, $transport->sent);
    }

    public static function blockedReferences(): array
    {
        return [
            'private literal' => ['//127.0.0.1/private', 'unsafe_address'],
            'private DNS' => ['//private.example.com/private', 'unsafe_address'],
            'user info' => ['//user:sk-test-secret@example.com/report', 'userinfo_forbidden'],
            'other scheme' => ['file:///private/report', 'invalid_scheme'],
            'opaque scheme' => ['javascript:alert(1)', 'invalid_scheme'],
            'malformed port' => ['//example.com:not-a-port/report?key=sk-test-secret', 'invalid_redirect'],
        ];
    }

    public function test_same_host_query_redirect_rechecks_dns(): void
    {
        [$client, $transport, $resolver] = $this->client([$this->redirect('?page=2'), $this->ok()], rebind: true);

        $this->assertBlocked('unsafe_address', fn () => $client->get((new Factory)->acceptJson(), 'https://example.com/a/report', 128, 1));

        $this->assertSame(['example.com', 'example.com'], $resolver->hosts);
        $this->assertCount(1, $transport->sent);
    }

    public function test_cross_origin_reference_clears_credentials_and_original_query_data(): void
    {
        [$client, $transport] = $this->client([$this->redirect('//cdn.example.com/a/../report?page=2'), $this->ok()]);
        $request = (new Factory)->acceptJson()->withToken('sk-test-secret')->withHeaders(['Cookie' => 'session=sk-test-session'])
            ->withOptions(['auth' => ['test-user', 'sk-test-password'], 'cert' => '/test-cert.pem', 'ssl_key' => '/test-key.pem']);

        $client->get($request, 'https://example.com/a/report', 128, 1, ['key' => 'sk-test-query']);

        $this->assertCount(2, $transport->sent);
        $next = $transport->sent[1];
        $this->assertSame('https://cdn.example.com/report?page=2', $next['url']);
        $this->assertTrue($next['crossOrigin']);
        $this->assertSame([], $next['data']);
        $this->assertSame('', $next['options']['headers']['Authorization']);
        $this->assertSame('', $next['options']['headers']['Cookie']);
        $this->assertNull($next['options']['auth']);
        $this->assertNull($next['options']['cert']);
        $this->assertNull($next['options']['ssl_key']);
        $this->assertFalse($next['options']['cookies']);
        $this->assertSame('page=2', $next['options']['query']);
    }

    public function test_redirect_budget_still_stops_a_query_loop(): void
    {
        [$client, $transport] = $this->client([$this->redirect('?page=2'), $this->redirect('?page=3'), $this->ok()]);

        $this->assertBlocked('redirect_limit_exceeded', fn () => $client->get((new Factory)->acceptJson(), 'https://example.com/a/report', 128, 1));
        $this->assertSame(['https://example.com/a/report', 'https://example.com/a/report?page=2'], array_column($transport->sent, 'url'));
    }

    public function test_redirect_response_size_is_checked_before_following(): void
    {
        [$client, $transport] = $this->client([new Response(new PsrResponse(302, ['Location' => '?page=2'], str_repeat('x', 129))), $this->ok()]);

        $this->assertBlocked('response_too_large', fn () => $client->get((new Factory)->acceptJson(), 'https://example.com/a/report', 128, 1));
        $this->assertCount(1, $transport->sent);
    }

    private function redirect(string $location): Response
    {
        return new Response(new PsrResponse(302, ['Location' => $location]));
    }

    private function ok(): Response
    {
        return new Response(new PsrResponse(200, [], 'finished'));
    }

    private function assertBlocked(string $reason, callable $request): void
    {
        try {
            $request();
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
            $this->assertSame('Outbound request blocked by security policy.', $exception->getMessage());
            $this->assertNull($exception->getPrevious(), 'Do not retain a parser exception containing the Location.');

            return;
        }

        $this->fail('Expected the redirect to be blocked: '.$reason);
    }

    private function client(array $responses, bool $rebind = false): array
    {
        $resolver = new class($rebind) implements HostResolver
        {
            public array $hosts = [];

            public function __construct(private readonly bool $rebind) {}

            public function resolve(string $host): array
            {
                $this->hosts[] = $host;

                return $host === 'private.example.com' || ($this->rebind && count($this->hosts) > 1)
                    ? ['127.0.0.1'] : ['93.184.216.34'];
            }
        };
        $transport = new class($responses) implements OutboundTransport
        {
            public array $sent = [];

            public function __construct(private array $responses) {}

            public function send(PendingRequest $request, string $method, ResolvedOutboundTarget $target, array $data, int $maxBytes, bool $crossOrigin = false): Response
            {
                $this->sent[] = ['url' => $target->url, 'method' => $method, 'data' => $data, 'options' => $request->getOptions(), 'crossOrigin' => $crossOrigin];

                return array_shift($this->responses);
            }
        };

        return [new SafeOutboundHttpClient($resolver, $transport, true), $transport, $resolver];
    }
}
