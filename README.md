# AdminBolt Plugin Starter

A complete, runnable AdminBolt panel plugin. Copy it, rename it, replace the
handlers.

One plugin, one rule: some domains may not be hosted here. Everything a plugin
can do is in here in service of that one rule, which is the point. A starter
that does eight unrelated things teaches nothing about how they fit together.

- **Refuses an operation.** A blocking `domain.creating` handler rejects
  domains ending in a configured suffix, with a message the customer reads.
- **Changes an input.** The same handler can pin every new domain to one PHP
  version, returned as a mutation rather than a second API call.
- **Calls the panel back.** A `domain.created` handler adds a TXT record
  through the client API, scoped to the account the hook came from.
- **Puts pages in the panel.** A client page that answers "what may I do", and
  an admin page that answers "what is this thing doing to my customers". Both
  are described in PHP and drawn by the panel with its own components.
- **Takes input.** A form on the client page checks a name against the policy
  before anybody tries to create it, with errors that come back on the field.
- **Runs a command in the account.** One declared command, `php -v`, run as
  the account's own user and shown as output on the page.
- **Draws in the panel itself.** Three [slots](#slots): a warning above every
  admin page while the policy is empty, a count in the admin footer, and the
  rule under the customer's navigation, where they will read it before they
  hit it.
- **Keeps its own state.** A small store file behind the counts and the list
  of recent refusals. No database, no panel tables.

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
bolt-plugin hook domain.creating \
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
BODY='{"hook":"domain.creating","delivery_id":"dlv_1","payload":{"domain":"shop.local"}}'
TS=$(date +%s)
SIG="v1=$(printf 'v1:%s:%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac 'any-value-for-local-testing' -hex | sed 's/.*= //')"

curl -X POST http://127.0.0.1:8731/ \
  -H "Content-Type: application/json" \
  -H "X-Bolt-Timestamp: $TS" \
  -H "X-Bolt-Signature: $SIG" \
  --data-binary "$BODY"
```

## The pages

`plugin.json` declares them, which is what puts them in the navigation:

```json
"ui": [
    { "panel": "client", "slug": "domain-policy", "title": "Domain Policy", "group": "Domains" },
    { "panel": "admin", "slug": "policy-admin", "title": "Domain Policy", "group": "Plugins" }
]
```

`src/Ui/PolicyPage.php` and `src/Ui/OperatorPage.php` say what is on them.
They return a description, not markup, so the pages match the rest of the
panel and there is nothing to escape. Render them without a browser:

```bash
bolt-plugin page domain-policy --account=acme
bolt-plugin page domain-policy --action=try --input='{"domain":"shop.local"}'
bolt-plugin page policy-admin --panel=admin
```

Two pages rather than one that hides half of itself: the panel routes an admin
page only to somebody it authenticated as an administrator, so the boundary is
the panel's rather than an `if` of yours. Where a customer view and an
operator view really are the same page, declare one slug on both panels and
branch on `$request->isAdmin()`.

## Slots

A page is somewhere a customer goes. A slot is something they meet without
going anywhere. This plugin has three:

```json
"slots": [
    { "panel": "admin", "position": "content.start", "slug": "unconfigured-notice",
      "label": "A notice above every admin page while no suffix is blocked", "cache": 120 },
    { "panel": "admin", "position": "footer", "slug": "footer-count",
      "label": "What the policy refused today, in the footer", "cache": 60 },
    { "panel": "client", "position": "sidebar.nav.end", "slug": "policy-note",
      "label": "The blocked suffixes, under the customer's navigation", "cache": 300 }
]
```

`src/Ui/Slots.php` answers them, and each one is written to earn its place:

- It is **short**. A slot is a corner of somebody else's screen, so the panel
  draws at most five components and no buttons.
- It is **true right now**, or it is nothing. Two of these three return an
  empty page when there is nothing to say, and the panel then draws nothing at
  all. A notice that is always there stops being read.
- It is **cheap**. A slot renders on pages that have nothing to do with this
  plugin, so the panel caches what it returns for the `cache` in the manifest
  and stops calling a plugin that failed.

Positions are the panel's own names, listed in `SlotPosition`, not the names
of whatever the panel renders with today. `footer` keeps meaning the footer.

## The command

One command, declared in the manifest and approved by name when the plugin is
installed:

```json
"commands": [
    { "name": "php-version", "label": "Check the PHP version a site runs on",
      "cwd": "required", "timeout": 30,
      "steps": [{ "program": "php", "args": ["-v"] }] }
]
```

The row action on the client page runs it through
`$plugin->clientFor($request)->cli()->run('php-version', cwd: $domain)`, and the
output goes on the page as an `Output` component.

The plugin never builds a command line. The panel builds it from its own
approved copy of this declaration, runs it as the account's own user inside
that account's home directory, and hands back what it printed. That is why
`api.scopes` has to include `client:cli:execute`, and why the administrator
installing the plugin is shown these lines in full.


## Tests

```bash
composer test
```

The suite runs the whole delivery path with no panel and no web server, and
checks that the shipped `plugin.json` is valid. That last test is worth
keeping in your own plugin: a manifest that fails validation cannot be
installed, and you would rather find out in CI than on a customer's server.

## Make it yours

1. `plugin.json`: change `id`, `name`, `description`, `author`. The id becomes
   the install directory and the API key label, so pick it once.
2. `composer.json`: change `name` and the `Acme\DomainPolicy` namespace.
3. `src/Handler/`: replace the two handlers.
4. `src/Ui/`: replace the pages and the slots. Keep the shape of `Slots.php`:
   a slot that returns an empty page when it has nothing to say is a slot
   people keep reading.
5. `plugin.json` again: declare the hooks you actually use, the narrowest
   `api.scopes` that work, and only the slots you can defend. Scopes, commands
   and slots are all shown to the administrator approving the install.

Then validate before you ship:

```bash
bolt-plugin validate
```

## Two rules worth internalising

**A blocking handler must be fast and must not depend on anything remote.** It
runs while the panel is holding a customer's request. A network call in a
blocking handler is a network call that will one day hold up domain creation
for its entire timeout. Do the slow work in a notification handler, which is
queued and retried.

**Ask for the narrowest scopes that work.** `client:dns-records:write` is a
reason to say yes. `admin:*:write` is a reason to say no.

**A slot is somebody else's screen.** It renders on pages that have nothing to
do with your plugin, for a person who did not ask for it. Put one there only
when it saves them a trip, and let it draw nothing the rest of the time.

## Documentation

- [Plugin structure](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/plugin-structure.md)
- [Hooks](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/hooks.md)
- [Calling the panel API](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/api.md)
- [Slots](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/slots.md)
- [Running commands](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/commands.md)
- [plugin.json](https://github.com/AdminBolt/plugin-sdk/blob/main/docs/manifest.md)

## License

MIT.
