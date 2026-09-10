<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Handler;

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
 */
final class BlockedSuffixPolicy implements HookHandler
{
    /** @var list<string> */
    private array $suffixes;

    public function __construct(string $suffixes, private readonly ?string $forcePhpVersion = null)
    {
        $this->suffixes = array_values(array_filter(array_map(
            static fn (string $suffix): string => strtolower(trim($suffix)),
            explode(',', $suffixes)
        )));
    }

    public function hooks(): array
    {
        return [Hook::BEFORE_DOMAIN_CREATION];
    }

    public function handle(HookRequest $request): HookResponse
    {
        $domain = strtolower(trim((string) $request->payload('domain')));

        foreach ($this->suffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($domain, $suffix)) {
                // Written for the customer who is about to read it, not for
                // the log. They cannot act on "policy check failed".
                return HookResponse::reject(sprintf(
                    'Domains ending in %s cannot be hosted here. Please use a publicly resolvable domain.',
                    $suffix
                ));
            }
        }

        if ($this->forcePhpVersion !== null) {
            // A mutation, not a second API call: the panel applies this to
            // the creation it is already performing. Only keys the hook
            // declares as mutable are accepted, and the panel re-validates
            // this one before using it.
            return HookResponse::mutate(['php_version' => $this->forcePhpVersion]);
        }

        return HookResponse::ok();
    }
}
