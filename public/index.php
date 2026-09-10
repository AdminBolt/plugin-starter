<?php

/**
 * The plugin's entrypoint.
 *
 * The panel serves this file for every hook delivery. Everything below runs
 * outside the panel: its own process, its own user, its own release cycle.
 */

declare(strict_types=1);

use Acme\DomainPolicy\Handler\BlockedSuffixPolicy;
use Acme\DomainPolicy\Handler\TagNewDomain;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Plugin;

require __DIR__ . '/../vendor/autoload.php';

$plugin = Plugin::boot(__DIR__);

// A blocking before_* handler. It runs inside the creation, so it can refuse
// it outright, and the message it returns is what the customer reads.
$plugin->register(new BlockedSuffixPolicy(
    suffixes: (string) $plugin->setting('blocked_suffixes', ''),
    forcePhpVersion: $plugin->setting('force_php_version') ?: null,
));

// An after_* handler. The domain already exists by the time this runs, so
// there is nothing to veto; it calls back into the panel instead.
if ($plugin->setting('tag_records', true)) {
    $plugin->register(new TagNewDomain($plugin));
}

// The panel fires this whenever an operator saves the settings form. A plugin
// that caches derived state rebuilds it here.
$plugin->on(Hook::PLUGIN_SETTINGS_UPDATED, function (HookRequest $hook) use ($plugin) {
    $plugin->logger()->info('Settings were changed in the panel', [
        'changed' => array_keys($hook->payload('changed', [])),
    ]);

    return HookResponse::ok();
});

$plugin->run();
