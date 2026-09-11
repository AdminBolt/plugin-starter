<?php

declare(strict_types=1);

namespace Acme\DomainPolicy;

use AdminBolt\Plugin\Storage\Store;

/**
 * What the policy has actually done, kept between requests.
 *
 * A plugin's own small state, in the store the panel guarantees is writable.
 * It is what gives the operator's page and the footer note something true to
 * say: a rule nobody can see working is a rule nobody trusts.
 *
 * Deliberately small. The store is a file, read on every render, so this
 * keeps a running count and the last few refusals rather than a log.
 */
final class Journal
{
    private const RECENT_LIMIT = 10;

    public function __construct(private readonly Store $store)
    {
    }

    public function record(string $domain, string $suffix, ?string $account): void
    {
        $today = gmdate('Y-m-d');
        $counts = (array) $this->store->get('refusals_by_day', []);
        $counts[$today] = (int) ($counts[$today] ?? 0) + 1;

        // Two weeks is enough to answer "is this rule catching anything" and
        // keeps the file the size of a file rather than a database.
        $counts = array_slice($counts, -14, null, true);

        $recent = (array) $this->store->get('recent_refusals', []);

        array_unshift($recent, [
            'domain' => $domain,
            'suffix' => $suffix,
            'account' => $account,
            'at' => gmdate('c'),
        ]);

        $this->store->merge([
            'refusals_by_day' => $counts,
            'recent_refusals' => array_slice($recent, 0, self::RECENT_LIMIT),
            'refusals_total' => (int) $this->store->get('refusals_total', 0) + 1,
        ]);
    }

    public function refusedToday(): int
    {
        $counts = (array) $this->store->get('refusals_by_day', []);

        return (int) ($counts[gmdate('Y-m-d')] ?? 0);
    }

    public function refusedEver(): int
    {
        return (int) $this->store->get('refusals_total', 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(): array
    {
        $recent = $this->store->get('recent_refusals', []);

        return is_array($recent) ? array_values($recent) : [];
    }
}
