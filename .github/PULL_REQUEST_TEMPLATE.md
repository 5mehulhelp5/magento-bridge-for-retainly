## What

<!-- What does this change do? -->

## Why

<!-- Why is it needed? Link the issue if there is one. -->

## Testing performed

<!-- Which Magento version, and what you actually ran. Not "should work". -->

- Magento version:
- PHP version:

## Merchant impact

<!-- Does a merchant have to do anything after upgrading? Re-run setup:upgrade,
     re-enter the API key, clear cache, re-compile? Say so here. -->

## Risk

<!-- What could this break? db_schema changes, cron timing, event observers
     firing inside the order transaction, outbox delivery semantics? -->

- [ ] I ran `php -l` and phpcs locally (see CONTRIBUTING.md)
- [ ] This touches `etc/db_schema.xml` (and `db_schema_whitelist.json` is regenerated)
- [ ] This changes the outbox delivery contract or idempotency keys
- [ ] This needs a coordinated release with the ZeroSlip backend
