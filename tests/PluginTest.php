<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Tests;

use Acme\DomainPolicy\Handler\BlockedSuffixPolicy;
use Acme\DomainPolicy\Handler\TagNewDomain;
use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The whole delivery path, with no panel and no web server.
 */
final class PluginTest extends TestCase
{
    private const HOOK_SECRET = 'test-hook-secret';

    private function plugin(array $settings = [], ?HttpClient $http = null): Plugin
    {
        return Plugin::create(
            Manifest::fromFile(__DIR__ . '/../plugin.json'),
            Config::fromArray([
                'panel' => ['url' => 'https://panel.test:2087'],
                'plugin' => ['id' => 'domain-policy'],
                'api' => ['key' => 'k', 'secret' => 's'],
                'hooks' => ['secret' => self::HOOK_SECRET],
                'settings' => $settings,
            ], 'test'),
            http: $http,
        );
    }

    /** @return array{0: array<string, string>, 1: string} */
    private function delivery(string $hook, array $payload): array
    {
        $body = json_encode([
            'hook' => $hook,
            'delivery_id' => 'dlv_test',
            'payload' => $payload,
            'context' => ['hosting_account' => ['id' => 7, 'username' => 'acme']],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();

        return [
            [
                Signature::HEADER_SIGNATURE => Signature::compute(self::HOOK_SECRET, $timestamp, $body),
                Signature::HEADER_TIMESTAMP => (string) $timestamp,
            ],
            $body,
        ];
    }

    public function test_the_shipped_manifest_is_valid(): void
    {
        self::assertSame([], Manifest::validate(
            json_decode((string) file_get_contents(__DIR__ . '/../plugin.json'), true, 512, JSON_THROW_ON_ERROR)
        ));
    }

    public function test_a_blocked_suffix_stops_the_creation(): void
    {
        $plugin = $this->plugin(['blocked_suffixes' => '.test, .local']);
        $plugin->register(new BlockedSuffixPolicy('.test, .local'));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'shop.LOCAL']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame('reject', $result->json()['status']);
        self::assertStringContainsString('.local', $result->json()['message']);
    }

    public function test_an_allowed_domain_passes(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy('.test'));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);

        self::assertSame('ok', $plugin->httpRuntime()->handle('POST', '/', $headers, $body)->json()['status']);
    }

    public function test_forcing_a_php_version_comes_back_as_a_mutation(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy('.test', '8.3'));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);

        self::assertSame(
            ['php_version' => '8.3'],
            $plugin->httpRuntime()->handle('POST', '/', $headers, $body)->json()['mutations']
        );
    }

    public function test_a_new_domain_gets_its_txt_record_on_the_right_account(): void
    {
        $http = new RecordingHttpClient(new HttpResponse(201, '{"id":99}'));
        $plugin = $this->plugin(http: $http);
        $plugin->register(new TagNewDomain($plugin));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATED, ['id' => 42, 'domain' => 'example.com']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame(99, $result->json()['data']['record_id']);
        self::assertSame('https://panel.test:2087/api/client/dns-records', $http->lastUrl);
        self::assertSame('acme', $http->lastHeaders['X-Hosting-Account']);
        self::assertSame(42, json_decode((string) $http->lastBody, true)['domain_id']);
    }

    public function test_a_missing_scope_is_reported_and_not_retried_forever(): void
    {
        $http = new RecordingHttpClient(new HttpResponse(403, '{"error":"API key does not have access to this endpoint or method"}'));
        $plugin = $this->plugin(http: $http);
        $plugin->register(new TagNewDomain($plugin));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATED, ['id' => 42]);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame('ok', $result->json()['status']);
        self::assertSame('missing dns scope', $result->json()['data']['skipped']);
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy('.test'));

        [, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.test']);

        self::assertSame(401, $plugin->httpRuntime()->handle('POST', '/', [], $body)->status);
    }
}

final class RecordingHttpClient implements HttpClient
{
    public string $lastUrl = '';

    public ?string $lastBody = null;

    /** @var array<string, string> */
    public array $lastHeaders = [];

    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;

        return $this->response;
    }
}
