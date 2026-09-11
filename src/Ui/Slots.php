<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Ui;

use Acme\DomainPolicy\Journal;
use Acme\DomainPolicy\Policy;
use AdminBolt\Plugin\Plugin;
use AdminBolt\Plugin\Ui\Alert;
use AdminBolt\Plugin\Ui\Page;
use AdminBolt\Plugin\Ui\Text;
use AdminBolt\Plugin\Ui\UiRequest;

/**
 * What this plugin draws in the panel's own chrome.
 *
 * A page is somewhere somebody goes. These are the things they meet without
 * going anywhere, and the difference is worth designing for: a slot is on a
 * screen the plugin has nothing to do with, so it earns its place only by
 * being short and by being true right now.
 *
 * Each of these is cheap on purpose. They read the settings and one small
 * file, and the panel holds on to the answer for as long as the manifest asks
 * (two minutes, one minute and five minutes here), so a slot is a round trip
 * a minute rather than one per page.
 */
final class Slots
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * A plugin that is installed and doing nothing should say so.
     *
     * Above the content of every admin page, and only while it is true: a
     * notice that is always there stops being read within a day.
     */
    public function unconfiguredNotice(UiRequest $request): Page
    {
        $page = Page::make('');

        if (!Policy::fromSettings($this->plugin)->isEmpty()) {
            return $page;
        }

        return $page->add(
            Alert::warning('Domain Policy is installed but blocks nothing. Add the suffixes you want refused in its settings.')
                ->title('No domain policy is in force')
        );
    }

    /**
     * One line in the footer of the admin area.
     *
     * The operator installed a thing that refuses customers' domains. How
     * often it is doing that is the one number worth putting where they will
     * see it without looking for it.
     */
    public function footerCount(UiRequest $request): Page
    {
        $journal = new Journal($this->plugin->store());
        $today = $journal->refusedToday();

        if ($today === 0) {
            return Page::make('');
        }

        return Page::make('')->add(Text::make(sprintf(
            'Domain Policy refused %d %s today.',
            $today,
            $today === 1 ? 'domain' : 'domains'
        )));
    }

    /**
     * Under the customer's navigation, where they are looking anyway.
     *
     * The rule, before they hit it. A customer who reads this here does not
     * open a ticket after a refusal they did not expect, which is the whole
     * argument for the slot existing.
     */
    public function policyNote(UiRequest $request): Page
    {
        $policy = Policy::fromSettings($this->plugin);

        if ($policy->isEmpty()) {
            return Page::make('');
        }

        // Markdown, because a slot has no buttons: a link is how it sends
        // somebody to the page with the whole story on it.
        return Page::make('')->add(Text::markdown(sprintf(
            "Domains ending in %s cannot be added here. [What this means](/client/plugins/domain-policy/domain-policy)",
            '`' . implode('`, `', $policy->suffixes()) . '`'
        )));
    }
}
