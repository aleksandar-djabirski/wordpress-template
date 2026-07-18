# Site Commerce Integrations

This directory is the **only** approved location for outbound HTTP calls
from commerce code — the architecture boundary this repo's naming contract
enforces for the commerce profile, mirroring the base profile's equivalent
rule for `web/app/plugins/site-integrations/`.

## What belongs here

Adapters that call out to third-party services on behalf of commerce
features: payment gateway calls, shipping-rate lookups, inventory or order
sync with an external system. Follow the same fake-vs-real pattern as
`SiteIntegrations\LeadDelivery` (`FakeLeadDelivery` vs `WebhookLeadDelivery`)
— a safe, log-only fake for development/staging, and a real HTTP-calling
implementation gated to production via `wp_get_environment_type()`.

Empty by design (Task 5 skeleton): no payment, shipping, or sync adapter
exists yet. Add them here, per-project, once real commerce behavior is in
scope — never elsewhere in this plugin.
