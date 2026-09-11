<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Handler;

use Acme\DomainPolicy\Journal;
use Acme\DomainPolicy\Policy;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookHandler;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;

/**
 * Refuses a domain whose name ends in a configured suffix, and optionally
 * pins every domain it does allow to one PHP version.
 *
 * This is the shape of a blocking hook: it runs while the panel is still
 * holding the user's request, it answers quickly, and it never calls out to
 * anything slow. A blocking handler that makes a network call is a blocking
 * handler that will one day hold up domain creation for its whole timeout.
 *
 * Writing the refusal to the journal is the one thing it does besides
 * deciding, and it is a few bytes to a local file. That is the budget: local
 * and small. Anything else belongs in a notification handler, which is queued
 * and retried and holds nobody up.
 */
final class BlockedSuffixPolicy implements HookHandler
{
    public function __construct(
        private readonly Policy $policy,
        private readonly ?Journal $journal = null,
    ) {
    }

    public function hooks(): array
    {
        return [Hook::DOMAIN_CREATING];
    }

    public function handle(HookRequest $request): HookResponse
    {
        $domain = strtolower(trim((string) $request->payload('domain')));
        $suffix = $this->policy->refuses($domain);

        if ($suffix !== null) {
            $this->journal?->record($domain, $suffix, $request->hostingAccountUsername());

            // Written for the customer who is about to read it, not for the
            // log. They cannot act on "policy check failed".
            return HookResponse::reject(sprintf(
                'Domains ending in %s cannot be hosted here. Please use a publicly resolvable domain.',
                $suffix
            ));
        }

        if ($this->policy->forcedPhpVersion() !== null) {
            // A mutation, not a second API call: the panel applies this to
            // the creation it is already performing. Only keys the hook
            // declares as mutable are accepted, and the panel re-validates
            // this one before using it.
            return HookResponse::mutate(['php_version' => $this->policy->forcedPhpVersion()]);
        }

        return HookResponse::ok();
    }
}
