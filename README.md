# AdminBolt Plugin Starter

A complete, runnable AdminBolt panel plugin. Copy it, rename it, replace the
handlers.

It demonstrates the three things every plugin does:

- **Refuses an operation.** A blocking `before_domain_creation` handler
  rejects domains ending in a configured suffix, with a message the customer
  reads.
- **Changes an input.** The same handler can pin every new domain to one PHP
  version, returned as a mutation rather than a second API call.
- **Calls the panel back.** An `after_domain_creation` handler adds a TXT
  record through the client API, scoped to the account the hook came from.

Nothing here is coupled to the panel. It is a PHP application with two
dependencies, and it ships and versions on its own.

## Run it locally

```bash
composer install
```

Create a `runtime.json` next to `plugin.json`. The panel writes this file at
install time; for development you write it yourself. It is gitignored, and it
holds a live API secret once you point it at a real panel.

```json
{
    "panel": { "url": "https://panel.example:2087" },
    "plugin": { "id": "domain-policy" },
    "api": { "key": "your-api-key", "secret": "your-api-secret" },
    "hooks": { "secret": "any-value-for-local-testing" },
    "settings": {
        "blocked_suffixes": ".test, .local",
        "force_php_version": "8.3",
        "tag_records": true,
        "log_level": "debug"
    },
    "paths": { "data": "var/data", "logs": "var/logs" }
}
```

Serve it:

```bash
BOLT_PLUGIN_DIR="$PWD" php -S 127.0.0.1:8731 -t public public/index.php
```

Deliver a hook to it:

```bash
bolt-plugin hook before_domain_creation \
  --payload='{"domain":"shop.local"}' \
  --url=http://127.0.0.1:8731
```

```json
{
    "status": "reject",
    "message": "Domains ending in .local cannot be hosted here. Please use a publicly resolvable domain."
}
```

Without the CLI, sign a delivery by hand. The signature covers the timestamp
and the exact bytes of the body:

```bash
BODY='{"hook":"before_domain_creation","delivery_id":"dlv_1","payload":{"domain":"shop.local"}}'
TS=$(date +%s)
SIG="v1=$(printf 'v1:%s:%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac 'any-value-for-local-testing' -hex | sed 's/.*= //')"

curl -X POST http://127.0.0.1:8731/ \
  -H "Content-Type: application/json" \
  -H "X-Bolt-Timestamp: $TS" \
  -H "X-Bolt-Signature: $SIG" \
  --data-binary "$BODY"
```

## Tests

```bash
composer test
```

The suite runs the whole delivery path with no panel and no web server, and
checks that the shipped `plugin.json` is valid. That last test is worth
keeping in your own plugin: a manifest that fails validation cannot be
installed, and you would rather find out in CI than on a customer's server.

## Make it yours

1. `plugin.json` — change `id`, `name`, `description`, `author`. The id
   becomes the install directory and the API key label, so pick it once.
2. `composer.json` — change `name` and the `Acme\DomainPolicy` namespace.
3. `src/Handler/` — replace the two handlers.
4. `plugin.json` again — declare the hooks you actually use and the narrowest
   `api.scopes` that work. Scopes are shown to the administrator approving the
   install.

Then validate before you ship:

```bash
bolt-plugin validate
```

## Two rules worth internalising

**A blocking handler must be fast and must not depend on anything remote.** It
runs while the panel is holding a customer's request. A network call in a
blocking handler is a network call that will one day hold up domain creation
for its entire timeout. Do the slow work in an `after_*` handler, which is
queued and retried.

**Ask for the narrowest scopes that work.** `client:dns-records:write` is a
reason to say yes. `admin:*:write` is a reason to say no.

## Documentation

- [Plugin structure](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/plugin-structure.md)
- [Hooks](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/hooks.md)
- [Calling the panel API](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/api.md)
- [plugin.json](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/manifest.md)

## License

MIT.
