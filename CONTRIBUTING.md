# Contributing

## Before you open a PR

`main` is protected: no direct pushes, no force-pushes, no branch deletion.
Everything lands through a pull request with green CI and one approval.

Outside contributors: fork the repo and open a PR against `main`. A maintainer
has to approve the workflow run on your first PR before CI will execute.

## Running the checks locally

CI runs exactly these. Nothing here needs a Magento installation.

```bash
# 1. Syntax, on the minimum supported PHP (8.1)
find src -type f \( -name '*.php' -o -name '*.phtml' \) -exec php -l {} \;

# 2. Magento coding standard. Installed OUTSIDE the repo on purpose —
#    `composer install` here would try to pull magento/framework from
#    repo.magento.com, which needs auth keys.
mkdir -p /tmp/phpcs && cd /tmp/phpcs
composer require --dev magento/magento-coding-standard
cd -
/tmp/phpcs/vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml src/

# 3. Package metadata and module identity
composer validate --strict --no-check-lock
python3 .github/scripts/check_module.py
```

The coding standard is enforced on **files your PR changes**, not repo-wide —
the module predates the standard and the backlog is grandfathered. The full
count is printed in every CI run's summary; don't add to it.

## Things that need extra care

- **`etc/db_schema.xml`** — regenerate `db_schema_whitelist.json`
  (`bin/magento setup:db-declaration:generate-whitelist --module-name=Retnly_MagentoBridge`)
  in the same PR, or the column will never be created on merchant stores.
- **Observers** — they run inside the order's database transaction. No HTTP
  calls, no slow work. Write to the outbox and let cron deliver it.
- **Outbox delivery** — changing the idempotency key format will re-deliver
  events merchants have already received. Call it out in the PR.
- **Version bumps** — `src/app/code/Retnly/MagentoBridge/composer.json` is what
  Magento Marketplace reads.

## Commit and PR conventions

One logical change per PR. Fill in the PR template, especially **Merchant
impact** — a change that silently requires `setup:upgrade` is a support ticket.
