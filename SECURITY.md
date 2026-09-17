# Security Policy

## Supported versions

Only the latest released version of `retnly/magento-sync` receives fixes.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private reporting:

[Report a vulnerability](https://github.com/asadshahjahan/magento-bridge-for-retainly/security/advisories/new)

or email <support@zeroslip.co> with `SECURITY` in the subject.

Please include the Magento version, the module version, and the steps to
reproduce. Expect an acknowledgement within 3 working days.

## Scope

This module stores a Retnly API key in Magento's configuration and POSTs store
events to the Retnly API. Findings around API key handling, the outbox table
(`retnly_event_outbox`), the frontend pixel, or the `Sw/Messaging` controller
are in scope. Issues in Magento core itself belong with Adobe.
