<?php

declare(strict_types=1);

namespace Acme\DomainPolicy;

use AdminBolt\Plugin\Plugin;

/**
 * The policy itself, in one place.
 *
 * The hook that refuses a domain, the page that explains the rule and the
 * note under the customer's navigation all have to agree about what is
 * blocked. They agree because they all ask this, rather than each parsing the
 * setting its own way.
 */
final class Policy
{
    /** @param list<string> $suffixes */
    public function __construct(
        private readonly array $suffixes,
        private readonly ?string $forcedPhpVersion = null,
    ) {
    }

    public static function fromSettings(Plugin $plugin): self
    {
        $forced = $plugin->setting('force_php_version');

        return new self(
            self::parse((string) $plugin->setting('blocked_suffixes', '')),
            is_string($forced) && trim($forced) !== '' ? trim($forced) : null,
        );
    }

    /**
     * @return list<string>
     */
    public static function parse(string $setting): array
    {
        return array_values(array_filter(array_map(
            static fn (string $suffix): string => strtolower(trim($suffix)),
            explode(',', $setting)
        )));
    }

    /**
     * The suffix that refuses this domain, or null if it is allowed.
     *
     * Returning which suffix matched rather than true: the customer reading
     * the refusal needs to know which rule caught them, and a page that says
     * "blocked" without saying why is a support ticket.
     */
    public function refuses(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        foreach ($this->suffixes as $suffix) {
            if ($suffix !== '' && str_ends_with($domain, $suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->suffixes === [];
    }

    /**
     * @return list<string>
     */
    public function suffixes(): array
    {
        return $this->suffixes;
    }

    public function forcedPhpVersion(): ?string
    {
        return $this->forcedPhpVersion;
    }
}
