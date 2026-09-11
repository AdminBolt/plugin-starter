<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Ui;

use Acme\DomainPolicy\Journal;
use Acme\DomainPolicy\Policy;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\Action;
use AdminBolt\Plugin\Ui\Alert;
use AdminBolt\Plugin\Ui\Column;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Section;
use AdminBolt\Plugin\Ui\Stat;
use AdminBolt\Plugin\Ui\Table;
use AdminBolt\Plugin\Ui\Text;
use AdminBolt\Plugin\Ui\UiRequest;
use AdminBolt\Plugin\Ui\UiResponse;

/**
 * The operator's view of the same plugin.
 *
 * The customer's page answers "what may I do"; this one answers "what is this
 * thing doing to my customers". They are different questions, which is why
 * the manifest declares a page on each panel rather than one page trying to
 * be both.
 *
 * Nothing here is scoped to an account: the panel only routes an admin page
 * to somebody it authenticated as an administrator, and the plugin is told
 * which one in the envelope.
 */
final class OperatorPage
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function render(UiRequest $request): Page
    {
        $policy = Policy::fromSettings($this->plugin);
        $journal = new Journal($this->plugin->store());

        $page = Page::make('Domain Policy')
            ->subheading('What this plugin is refusing, and why')
            ->headerActions(
                Action::make('forget-refusals', 'Clear the list')
                    ->icon('heroicon-o-trash')
                    ->confirm('The counts are kept. Only the ten rows below go.')
            )
            ->stats(
                Stat::make('Refused today', $journal->refusedToday())
                    ->icon('heroicon-o-no-symbol')
                    ->color($journal->refusedToday() > 0 ? 'warning' : 'gray'),
                Stat::make('Refused in total', $journal->refusedEver())
                    ->description('Since the plugin was installed'),
                Stat::make('Suffixes blocked', count($policy->suffixes()))
                    ->color($policy->isEmpty() ? 'danger' : 'success'),
            );

        if ($policy->isEmpty()) {
            $page->add(
                Alert::warning('Nothing is blocked, so every domain is allowed. Add suffixes in this plugin\'s settings.')
                    ->title('The policy is empty')
            );
        }

        $page->add(
            Section::make('The rule')
                ->icon('heroicon-o-shield-check')
                ->add(
                    Text::markdown($this->describeRule($policy)),
                )
        );

        return $page->add(
            Section::make('Recently refused')
                ->description('The last ten, newest first. Kept in the plugin\'s own store, not the panel\'s.')
                ->add(
                    Table::make()
                        ->columns(
                            Column::make('domain', 'Domain'),
                            Column::make('suffix', 'Matched')->badge(['' => 'gray']),
                            Column::make('account', 'Account'),
                            Column::make('at', 'When')->dateTime(),
                        )
                        ->rows($journal->recent())
                        ->keyedBy('domain')
                        ->emptyState('Nothing has been refused yet.', 'heroicon-o-check-circle')
                )
        );
    }

    /**
     * The settings form belongs to the panel, so this page explains rather
     * than edits: two places to change one setting is one place too many.
     */
    private function describeRule(Policy $policy): string
    {
        $lines = [];

        $lines[] = $policy->isEmpty()
            ? 'No suffix is blocked. Every domain a customer asks for is allowed.'
            : sprintf(
                'A domain ending in %s is refused while it is being created, and the customer is told which suffix caught it.',
                '`' . implode('`, `', $policy->suffixes()) . '`'
            );

        $lines[] = $policy->forcedPhpVersion() === null
            ? 'Domains that are allowed keep whatever PHP version their hosting plan gives them.'
            : sprintf(
                'Domains that are allowed are pinned to PHP `%s`, as a mutation on the creation itself rather than a second call.',
                $policy->forcedPhpVersion()
            );

        $lines[] = 'Both come from this plugin\'s settings in the panel.';

        return implode("\n\n", $lines);
    }

    /**
     * A header action, to show what one costs: the panel calls this, the
     * plugin answers with what to say, and the page redraws.
     */
    public function forget(UiRequest $request): UiResponse
    {
        $this->plugin->store()->forget('recent_refusals');

        return UiResponse::notify('Cleared the list of recent refusals. The counts are kept.');
    }
}
