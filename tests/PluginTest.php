<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Tests;

use Acme\DomainPolicy\Handler\BlockedSuffixPolicy;
use Acme\DomainPolicy\Journal;
use Acme\DomainPolicy\Policy;
use Acme\DomainPolicy\Handler\TagNewDomain;
use Acme\DomainPolicy\Ui\OperatorPage;
use Acme\DomainPolicy\Ui\PolicyPage;
use Acme\DomainPolicy\Ui\Slots;
use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Http\HttpClient;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\UiRequest;
use PHPUnit\Framework\TestCase;

/**
 * The whole delivery path, with no panel and no web server.
 */
final class PluginTest extends TestCase
{
    private const HOOK_SECRET = 'test-hook-secret';

    private function plugin(array $settings = [], ?HttpClient $http = null, ?string $data = null): Plugin
    {
        return Plugin::create(
            Manifest::fromFile(__DIR__ . '/../plugin.json'),
            Config::fromArray([
                'panel' => ['url' => 'https://panel.test:2087'],
                'plugin' => ['id' => 'domain-policy'],
                'api' => ['key' => 'k', 'secret' => 's'],
                'hooks' => ['secret' => self::HOOK_SECRET],
                'settings' => $settings,
                'paths' => $data === null ? [] : ['data' => $data, 'logs' => $data],
            ], 'test'),
            http: $http,
        );
    }

    /**
     * A directory that goes away with the test, for the one thing a plugin
     * keeps between requests.
     */
    private function dataDirectory(): string
    {
        $path = sys_get_temp_dir() . '/domain-policy-test-' . bin2hex(random_bytes(6));
        mkdir($path, 0o755, true);
        $this->directories[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach ((array) glob($directory . '/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($directory);
        }

        parent::tearDown();
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
        $plugin->register(new BlockedSuffixPolicy(new Policy(Policy::parse('.test, .local'))));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'shop.LOCAL']);
        $result = $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        self::assertSame('reject', $result->json()['status']);
        self::assertStringContainsString('.local', $result->json()['message']);
    }

    public function test_an_allowed_domain_passes(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy(new Policy(['.test'])));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'example.com']);

        self::assertSame('ok', $plugin->httpRuntime()->handle('POST', '/', $headers, $body)->json()['status']);
    }

    public function test_forcing_a_php_version_comes_back_as_a_mutation(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy(new Policy(['.test'], '8.3')));

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

    /** @return array{0: array<string, string>, 1: string} */
    private function uiDelivery(string $slug, ?string $action = null, array $extra = []): array
    {
        $body = json_encode([
            'slug' => $slug,
            'panel' => 'client',
            'viewer' => ['id' => 3, 'name' => 'Jo'],
            'hosting_account' => ['id' => 7, 'username' => 'acme'],
            'action' => $action,
            ...$extra,
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

    public function test_the_page_lists_the_accounts_domains_and_flags_the_blocked_ones(): void
    {
        $http = new RecordingHttpClient(new HttpResponse(200, json_encode([
            ['domain' => 'example.com', 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['domain' => 'staging.local', 'created_at' => '2026-02-01T00:00:00+00:00'],
        ], JSON_THROW_ON_ERROR)));

        $plugin = $this->plugin(http: $http);
        $page = new PolicyPage($plugin, new Policy(['.local']));
        $plugin->page('domain-policy', $page->render(...));

        [$headers, $body] = $this->uiDelivery('domain-policy');
        $rendered = $plugin->httpRuntime()->handle('POST', '/ui/domain-policy', $headers, $body)->json()['page'];

        self::assertSame('Domain Policy', $rendered['heading']);
        self::assertSame('2', $rendered['stats'][0]['value']);
        self::assertSame('1', $rendered['stats'][1]['value']);

        $table = $rendered['components'][1];
        self::assertSame('allowed', $table['rows'][0]['status']);
        self::assertSame('blocked', $table['rows'][1]['status']);

        // The page is read for one account, and the panel said which.
        self::assertSame('acme', $http->lastHeaders['X-Hosting-Account']);
    }

    public function test_a_row_action_recomputes_rather_than_trusting_the_key(): void
    {
        $plugin = $this->plugin();
        $page = new PolicyPage($plugin, new Policy(['.local']));
        $plugin->action('check', $page->check(...));

        [$headers, $body] = $this->uiDelivery('domain-policy', 'check', ['arguments' => ['key' => 'shop.local']]);
        $result = $plugin->httpRuntime()->handle('POST', '/ui/domain-policy/check', $headers, $body);

        self::assertSame('warning', $result->json()['result']['level']);
        self::assertStringContainsString('would be refused', $result->json()['result']['message']);
    }

    public function test_the_form_checks_a_name_without_creating_anything(): void
    {
        $plugin = $this->plugin();
        $page = new PolicyPage($plugin, new Policy(['.local']));
        $plugin->action('try', $page->try(...));

        [$headers, $body] = $this->uiDelivery('domain-policy', 'try', ['input' => ['domain' => 'shop.local']]);
        $result = $plugin->httpRuntime()->handle('POST', '/ui/domain-policy/try', $headers, $body)->json()['result'];

        self::assertSame('warning', $result['level']);
        self::assertStringContainsString('would be refused', $result['message']);
    }

    /**
     * The message belongs under the input it is about, which is what the
     * panel does with errors keyed by field name.
     */
    public function test_a_name_that_is_not_a_name_comes_back_on_the_field(): void
    {
        $plugin = $this->plugin();
        $page = new PolicyPage($plugin, new Policy([]));
        $plugin->action('try', $page->try(...));

        [$headers, $body] = $this->uiDelivery('domain-policy', 'try', ['input' => ['domain' => 'not a domain']]);
        $result = $plugin->httpRuntime()->handle('POST', '/ui/domain-policy/try', $headers, $body)->json()['result'];

        self::assertArrayHasKey('domain', $result['errors']);
    }

    public function test_a_slot_says_the_rule_where_the_customer_is_already_looking(): void
    {
        $plugin = $this->plugin(['blocked_suffixes' => '.test, .local']);
        $slots = new Slots($plugin);
        $plugin->slot('policy-note', $slots->policyNote(...));

        [$headers, $body] = $this->uiDelivery('policy-note');
        $rendered = $plugin->httpRuntime()->handle('POST', '/ui/policy-note', $headers, $body)->json()['page'];

        self::assertCount(1, $rendered['components'], 'a slot is one short thing, not a page');
        self::assertStringContainsString('.local', $rendered['components'][0]['content']);
    }

    /**
     * A slot with nothing to say says nothing, and the panel draws nothing.
     * A footer note that is always there stops being read.
     */
    public function test_a_slot_with_nothing_to_say_draws_nothing(): void
    {
        $plugin = $this->plugin(['blocked_suffixes' => '']);
        $slots = new Slots($plugin);
        $plugin->slot('policy-note', $slots->policyNote(...));

        [$headers, $body] = $this->uiDelivery('policy-note');
        $rendered = $plugin->httpRuntime()->handle('POST', '/ui/policy-note', $headers, $body)->json()['page'];

        self::assertSame([], $rendered['components']);
    }

    public function test_the_notice_slot_appears_only_while_the_plugin_is_doing_nothing(): void
    {
        $unconfigured = $this->plugin(['blocked_suffixes' => '']);
        $unconfigured->slot('unconfigured-notice', (new Slots($unconfigured))->unconfiguredNotice(...));

        [$headers, $body] = $this->uiDelivery('unconfigured-notice', null, ['panel' => 'admin']);
        $rendered = $unconfigured->httpRuntime()->handle('POST', '/ui/unconfigured-notice', $headers, $body)->json()['page'];

        self::assertSame('alert', $rendered['components'][0]['type']);

        $configured = $this->plugin(['blocked_suffixes' => '.test']);
        $configured->slot('unconfigured-notice', (new Slots($configured))->unconfiguredNotice(...));

        [$headers, $body] = $this->uiDelivery('unconfigured-notice', null, ['panel' => 'admin']);
        $rendered = $configured->httpRuntime()->handle('POST', '/ui/unconfigured-notice', $headers, $body)->json()['page'];

        self::assertSame([], $rendered['components']);
    }

    /**
     * What the operator sees is what the policy actually did, which only
     * exists because the blocking handler wrote it down.
     */
    public function test_a_refusal_is_recorded_and_reaches_the_operators_page_and_the_footer(): void
    {
        $data = $this->dataDirectory();
        $plugin = $this->plugin(['blocked_suffixes' => '.local'], data: $data);
        $journal = new Journal($plugin->store());

        $plugin->register(new BlockedSuffixPolicy(new Policy(['.local']), $journal));

        [$headers, $body] = $this->delivery(Hook::DOMAIN_CREATING, ['domain' => 'shop.local']);
        $plugin->httpRuntime()->handle('POST', '/', $headers, $body);

        $operator = new OperatorPage($plugin);
        $plugin->page('policy-admin', $operator->render(...));

        [$headers, $body] = $this->uiDelivery('policy-admin', null, ['panel' => 'admin']);
        $rendered = $plugin->httpRuntime()->handle('POST', '/ui/policy-admin', $headers, $body)->json()['page'];

        self::assertSame('1', $rendered['stats'][0]['value'], 'refused today');
        self::assertSame('1', $rendered['stats'][1]['value'], 'refused in total');

        $table = $rendered['components'][1]['components'][0];
        self::assertSame('shop.local', $table['rows'][0]['domain']);
        self::assertSame('acme', $table['rows'][0]['account']);

        $plugin->slot('footer-count', (new Slots($plugin))->footerCount(...));

        [$headers, $body] = $this->uiDelivery('footer-count', null, ['panel' => 'admin']);
        $footer = $plugin->httpRuntime()->handle('POST', '/ui/footer-count', $headers, $body)->json()['page'];

        self::assertStringContainsString('refused 1 domain today', $footer['components'][0]['content']);
    }

    public function test_an_unsigned_page_request_is_refused(): void
    {
        $plugin = $this->plugin();
        $page = new PolicyPage($plugin, new Policy([]));
        $plugin->page('domain-policy', $page->render(...));

        [, $body] = $this->uiDelivery('domain-policy');

        self::assertSame(401, $plugin->httpRuntime()->handle('POST', '/ui/domain-policy', [], $body)->status);
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $plugin = $this->plugin();
        $plugin->register(new BlockedSuffixPolicy(new Policy(['.test'])));

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
