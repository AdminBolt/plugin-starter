<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Ui;

use Acme\DomainPolicy\Policy;
use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\Action;
use AdminBolt\Plugin\Ui\Alert;
use AdminBolt\Plugin\Ui\Column;
use AdminBolt\Plugin\Ui\Field;
use AdminBolt\Plugin\Ui\Form;
use AdminBolt\Plugin\Ui\Output;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Section;
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
 *
 * Between them, the page and its actions use most of what a plugin page can
 * do: the card row across the top, a table with a row action, a form that
 * submits back, a command run in the customer's own account, and the output
 * it printed.
 */
final class PolicyPage
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly Policy $policy,
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
                'id' => $domain['id'] ?? null,
                'status' => $this->policy->refuses($name) === null ? 'allowed' : 'blocked',
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

        if ($this->policy->isEmpty()) {
            $page->add(Alert::info('No suffixes are blocked, so every domain is allowed.'));
        } else {
            $page->add(Text::markdown(sprintf(
                'New domains ending in %s are refused when they are created.',
                '`' . implode('`, `', $this->policy->suffixes()) . '`'
            )));
        }

        $page->add(
            Table::make()
                ->columns(
                    Column::make('domain', 'Domain'),
                    Column::make('status', 'Policy')->badge(['allowed' => 'success', 'blocked' => 'danger']),
                    Column::make('created_at', 'Added')->dateTime(),
                )
                ->rows($rows)
                ->keyedBy('domain')
                ->rowActions(
                    Action::make('check', 'Check'),
                    Action::make('php-version', 'PHP version')->icon('heroicon-o-command-line'),
                )
                ->emptyState('This account has no domains yet.', 'heroicon-o-globe-alt'),
        );

        // A form, so somebody can find out before they buy the name rather
        // than after the panel refuses it.
        $page->add(
            Section::make('Try a name')
                ->description('Nothing is created. This only answers whether the policy would allow it.')
                ->add(
                    Form::make('try')
                        ->submitLabel('Check the name')
                        ->fields(
                            Field::text('domain', 'Domain')
                                ->placeholder('shop.example.com')
                                ->required()
                                ->help('Just the name. Whatever you type here is checked against the same rule the panel applies.'),
                        )
                )
        );

        // What the last command run on this account printed. The page holds
        // nothing itself, so this survives a refresh and a different browser.
        $lastRun = $this->plugin->store($request)->get('last_php_version');

        if (is_array($lastRun)) {
            $page->add(
                Output::make((string) ($lastRun['output'] ?? ''))
                    ->title('php -v in ' . (string) ($lastRun['domain'] ?? 'the account'))
                    ->status(($lastRun['ok'] ?? false) === true ? 'success' : 'danger')
                    ->lines(12)
            );
        }

        return $page;
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

        $suffix = $this->policy->refuses($domain);

        return $suffix === null
            ? UiResponse::notify(sprintf('%s is allowed.', $domain))
            : UiResponse::warning(sprintf('%s ends in %s and would be refused.', $domain, $suffix));
    }

    /**
     * The form's submission.
     *
     * Input arrives named as the fields were declared, and it is checked here
     * rather than in the browser: the browser is not where the rule lives.
     */
    public function try(UiRequest $request): UiResponse
    {
        $domain = strtolower(trim((string) $request->input('domain', '')));

        if ($domain === '' || !str_contains($domain, '.')) {
            // Keyed by field name, so the panel puts the message under the
            // input it belongs to instead of in a notification nobody
            // associates with anything.
            return UiResponse::invalid(['domain' => 'That is not a domain name.']);
        }

        $suffix = $this->policy->refuses($domain);

        return $suffix === null
            ? UiResponse::notify(sprintf('%s would be allowed.', $domain))
            : UiResponse::warning(sprintf('%s would be refused: it ends in %s.', $domain, $suffix));
    }

    /**
     * Run the one command this plugin declared, in the customer's account.
     *
     * The panel builds the command line from its own approved copy of the
     * manifest, runs it as the account's own user inside that account's home,
     * and hands back what it printed. The plugin supplies only which domain,
     * which is why there is nothing to escape here.
     */
    public function phpVersion(UiRequest $request): UiResponse
    {
        $domain = trim((string) $request->argument('key'));

        if ($domain === '') {
            return UiResponse::error('No domain was named.');
        }

        try {
            $result = $this->plugin->clientFor($request)->cli()->run(
                'php-version',
                cwd: $domain,
            );
        } catch (ApiException $e) {
            if ($e->isAuthorizationFailure()) {
                return UiResponse::error(
                    'This plugin has not been approved to run commands. Add client:cli:execute to api.scopes and install it again.'
                );
            }

            return UiResponse::error('Could not run the command: ' . $e->getMessage());
        }

        // Kept so the page can draw it again on the next render, rather than
        // living in a notification that disappears.
        $this->plugin->store($request)->put('last_php_version', [
            'domain' => $domain,
            'ok' => $result->ok(),
            'output' => $result->output(),
        ]);

        return $result->ok()
            ? UiResponse::notify($result->lastLine())
            : UiResponse::error($result->message ?? 'The command failed.');
    }

    public function recheck(UiRequest $request): UiResponse
    {
        return UiResponse::refresh();
    }
}
