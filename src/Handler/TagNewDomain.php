<?php

declare(strict_types=1);

namespace Acme\DomainPolicy\Handler;

use AdminBolt\Plugin\Exception\ApiException;
use AdminBolt\Plugin\Hook\Hook;
use AdminBolt\Plugin\Hook\HookHandler;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Plugin;

/**
 * Adds a TXT record to every new domain, through the panel's client API.
 *
 * The shape of an after_* handler: the domain already exists, so there is
 * nothing to veto, and the work is a call back into the panel scoped to the
 * account the hook came from.
 */
final class TagNewDomain implements HookHandler
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly string $recordName = '_domain-policy',
    ) {
    }

    public function hooks(): array
    {
        return [Hook::AFTER_DOMAIN_CREATION];
    }

    public function handle(HookRequest $request): HookResponse
    {
        $domainId = $request->payload('id');

        if (!is_numeric($domainId)) {
            // Nothing to act on, and nothing wrong either. Acknowledge rather
            // than erroring, so the panel does not retry a delivery that will
            // never succeed.
            return HookResponse::ok(['skipped' => 'no domain id in payload']);
        }

        try {
            // clientFor() scopes the call to the account the hook belongs to,
            // so the plugin cannot touch anyone else's DNS even by accident.
            $record = $this->plugin->clientFor($request)->dnsRecords()->createRecord(
                domainId: (int) $domainId,
                type: 'TXT',
                name: $this->recordName,
                content: sprintf('checked-at=%s', gmdate('c')),
                ttl: 3600,
            );
        } catch (ApiException $e) {
            if ($e->isAuthorizationFailure()) {
                // The key is not scoped for this. Retrying will never help,
                // so tell the operator instead of failing the delivery.
                $this->plugin->logger()->error('Plugin key cannot write DNS records', [
                    'status' => $e->status,
                    'hint' => 'Add client:dns-records:write to api.scopes in plugin.json and reinstall.',
                ]);

                return HookResponse::ok(['skipped' => 'missing dns scope']);
            }

            // Anything else is worth another attempt: the panel retries an
            // after_* delivery that comes back as an error.
            return HookResponse::error('Could not create the TXT record: ' . $e->getMessage());
        }

        return HookResponse::ok(['record_id' => $record['id'] ?? null]);
    }
}
