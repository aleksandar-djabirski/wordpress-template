# Monitoring Contract

What every project built from this starter must have monitored before
launch, regardless of host. This starter ships the application-level
safety nets (`wp agency verify-env`, `FileModGuard`, environment-scoped
lead delivery); it does not ship monitoring infrastructure — that's a
per-project/per-host setup this contract defines the requirements for.

## Uptime

- An external check (outside the hosting provider's own network) hitting
  the homepage and one authenticated admin URL, at an interval of 5
  minutes or less.
- Alert on N consecutive failures (2–3, to absorb transient blips) via a
  channel someone actually monitors — not just an inbox.
- Track uptime against `WP_HOME`/the production URL specifically, not an
  internal health endpoint that can stay green while the public site is
  down (e.g. behind a CDN or a broken vhost).

## Errors

- PHP error logging is enabled per environment already
  (`config/environments/*.php` sets `WP_DEBUG_LOG` in development;
  production should log to a file or service the host can ship off-box —
  never `WP_DEBUG_DISPLAY` in production, which the base config already
  keeps off).
- Ship PHP fatal/warning-level logs to a monitored destination (log
  aggregator, error-tracking service) with alerting on a fatal-error rate
  spike, not just their existence.
- `AgencyPlatform\Logging\Logger::log()`'s structured JSON lines are
  designed to be machine-parseable by whatever log pipeline the host
  feeds into — redaction of secret-looking keys happens before the line
  is ever written, so it's safe to ship these logs off-box.

## Cron

- WordPress's pseudo-cron (`DISABLE_WP_CRON` is not set by this starter,
  so wp-cron runs on request traffic by default) is unreliable on
  low-traffic sites. Every project must run real cron —
  `wp cron event run --due-now` on a system crontab, or the host's
  managed WP-cron equivalent — and monitor that it actually executed
  recently (e.g. `wp cron event list` showing no wildly overdue events),
  not just that the cron job process itself didn't error.
- Any project-specific scheduled task (a custom `wp_schedule_event`
  registration) needs its own last-run/last-success signal, not just
  reliance on cron running in general.

## Domain / certificate

- TLS certificate expiry monitored with enough lead time to renew
  manually if automated renewal fails (alert at 30 and 7 days out, at
  minimum).
- Domain expiry/registration monitored the same way — a lapsed domain is
  an outage indistinguishable from a hosting failure to visitors.

## Escalation

Every monitor above needs a named owner and a defined response time per
project — a monitor with no one accountable for acting on it is
equivalent to not having it.
