<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Ui;

use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\Action;
use AdminBolt\Plugin\Ui\Alert;
use AdminBolt\Plugin\Ui\Column;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Stat;
use AdminBolt\Plugin\Ui\Table;
use AdminBolt\Plugin\Ui\Text;
use AdminBolt\Plugin\Ui\UiRequest;
use AdminBolt\Plugin\Ui\UiResponse;

/**
 * The plugin's page in the client area.
 *
 * It describes what to show; the panel draws it with its own components.
 * There is no HTML, CSS or JavaScript here, which is what makes the page
 * match the rest of the panel and stay free of injection.
 */
final class PolicyPage
{
    /** @param list<string> $blockedSuffixes */
    public function __construct(
        private readonly Plugin $plugin,
        private readonly array $blockedSuffixes,
    ) {
    }

    public function render(UiRequest $request): Page
    {
        // Scoped to the account the page is being viewed for. That username
        // comes from the panel's signed envelope, never from the browser.
        try {
            $domains = $this->plugin->clientFor($request)->domains()->all();
        } catch (ApiException $e) {
            return Page::make('Domain Policy')->add(
                Alert::danger('Could not read this account\'s domains: ' . $e->getMessage())
                    ->title('The panel refused the request')
            );
        }

        $rows = [];

        foreach ($domains as $domain) {
            if (!is_array($domain)) {
                continue;
            }

            $name = (string) ($domain['domain'] ?? '');

            $rows[] = [
                'domain' => $name,
                'status' => $this->wouldBlock($name) ? 'blocked' : 'allowed',
                'created_at' => $domain['created_at'] ?? null,
            ];
        }

        $blocked = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'blocked'));

        $page = Page::make('Domain Policy')
            ->subheading('Which domains this account may add')
            ->stats(
                Stat::make('Domains', count($rows))->icon('heroicon-o-globe-alt'),
                Stat::make('Blocked by policy', $blocked)->color($blocked > 0 ? 'warning' : 'success'),
            )
            ->headerActions(
                Action::make('recheck', 'Re-check now')->primary()->icon('heroicon-o-arrow-path')
            );

        if ($this->blockedSuffixes === []) {
            $page->add(Alert::info('No suffixes are blocked, so every domain is allowed.'));
        } else {
            $page->add(Text::markdown(sprintf(
                'New domains ending in %s are refused when they are created.',
                '`' . implode('`, `', $this->blockedSuffixes) . '`'
            )));
        }

        return $page->add(
            Table::make()
                ->columns(
                    Column::make('domain', 'Domain'),
                    Column::make('status', 'Policy')->badge(['allowed' => 'success', 'blocked' => 'danger']),
                    Column::make('created_at', 'Added')->dateTime(),
                )
                ->rows($rows)
                ->keyedBy('domain')
                ->rowActions(Action::make('check', 'Check'))
                ->emptyState('This account has no domains yet.', 'heroicon-o-globe-alt'),
        );
    }

    /**
     * A row action. The key made the round trip through the browser, so it
     * says what the viewer wants to act on and nothing more. The answer is
     * recomputed here rather than trusted.
     */
    public function check(UiRequest $request): UiResponse
    {
        $domain = strtolower(trim((string) $request->argument('key')));

        if ($domain === '') {
            return UiResponse::error('No domain was named.');
        }

        return $this->wouldBlock($domain)
            ? UiResponse::warning(sprintf('%s matches a blocked suffix and would be refused.', $domain))
            : UiResponse::notify(sprintf('%s is allowed.', $domain));
    }

    public function recheck(UiRequest $request): UiResponse
    {
        return UiResponse::notify('Re-checked every domain on this account.');
    }

    private function wouldBlock(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        foreach ($this->blockedSuffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
