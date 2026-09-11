<?php

/**
 * The plugin's entrypoint.
 *
 * The panel serves this file for every hook delivery, every page render and
 * every slot. Everything below runs outside the panel: its own process, its
 * own user, its own release cycle.
 *
 * Read top to bottom it is also the list of what a plugin can be: something
 * that refuses an operation, something that reacts to one, pages in two
 * different panels, buttons and forms on those pages, a command run inside a
 * customer's account, and a few small things drawn in the panel's own chrome.
 */

declare(strict_types=1);

use Acme\DomainPolicy\Handler\BlockedSuffixPolicy;
use Acme\DomainPolicy\Handler\TagNewDomain;
use Acme\DomainPolicy\Journal;
use Acme\DomainPolicy\Policy;
use Acme\DomainPolicy\Ui\OperatorPage;
use Acme\DomainPolicy\Ui\PolicyPage;
use Acme\DomainPolicy\Ui\Slots;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Plugin;

require __DIR__ . '/../vendor/autoload.php';

$plugin = Plugin::boot(__DIR__);

// One reading of the settings, shared by everything that has to agree about
// what the policy is.
$policy = Policy::fromSettings($plugin);
$journal = new Journal($plugin->store());

// A blocking handler. It runs inside the creation, so it can refuse it
// outright, and the message it returns is what the customer reads.
$plugin->register(new BlockedSuffixPolicy($policy, $journal));

// A notification handler. The domain already exists by the time this runs,
// so there is nothing to veto; it calls back into the panel instead.
if ($plugin->setting('tag_records', true)) {
    $plugin->register(new TagNewDomain($plugin));
}

// The panel fires this whenever an operator saves the settings form. A plugin
// that caches derived state rebuilds it here.
$plugin->on(Hook::PLUGIN_CONFIGURED, function (HookRequest $hook) use ($plugin) {
    $plugin->logger()->info('Settings were changed in the panel', [
        'changed' => array_keys($hook->payload('changed', [])),
    ]);

    return HookResponse::ok();
});

// The customer's page, declared under "ui" in plugin.json. It describes what
// to show and the panel renders it natively, so there is no HTML here and the
// page matches the rest of the panel.
$page = new PolicyPage($plugin, $policy);

$plugin->page('domain-policy', $page->render(...));
$plugin->action('check', $page->check(...));
$plugin->action('php-version', $page->phpVersion(...));
$plugin->action('try', $page->try(...));
$plugin->action('recheck', $page->recheck(...));

// The operator's page, in the admin area. The same plugin answering a
// different question for a different person, which is why it is a page of its
// own rather than a section nobody else should see.
$operator = new OperatorPage($plugin);

$plugin->page('policy-admin', $operator->render(...));
$plugin->action('forget-refusals', $operator->forget(...));

// The slots, declared under "slots" in plugin.json. These are drawn in the
// panel's own footer, sidebar and page headers, on screens that have nothing
// to do with this plugin, so each one is short, true right now, and cheap to
// answer.
$slots = new Slots($plugin);

$plugin->slot('unconfigured-notice', $slots->unconfiguredNotice(...));
$plugin->slot('footer-count', $slots->footerCount(...));
$plugin->slot('policy-note', $slots->policyNote(...));

$plugin->run();
