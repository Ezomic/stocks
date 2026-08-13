# Stocks — Project context for Claude

## What this is

A personal stock portfolio manager for Robbin Thijssen. Tracks positions across US stocks, EU
stocks, ETFs, and crypto. Automated take-profit and stop-loss rules are evaluated every minute
against live IBKR prices, and orders are placed automatically when thresholds are crossed.

## Stack

- **PHP 8.4, Laravel 13** — Blade only, no Inertia, no Vue, no Livewire
- **SQLite** — single file at `database/database.sqlite`
- **No npm** — CSS in `public/css/app.css` loaded via plain `<link>`
- **Interactive Brokers Client Portal Web API** — local Java gateway process

## Running locally

Site runs under **Herd** at `stocks.test`. The IBKR gateway must also be running for price
sync and order placement to work.

```bash
php artisan migrate          # run pending migrations
php artisan db:seed          # create admin user (robbin_thijssen@hotmail.nl)
php artisan test             # Pest suite

# IBKR commands
php artisan ibkr:import-positions   # pull current positions from broker
php artisan ibkr:sync-prices        # fetch latest prices (runs via scheduler)
php artisan ibkr:evaluate-rules     # check TP/SL thresholds (runs via scheduler)
php artisan ibkr:sync-orders        # update order statuses (runs via scheduler)
php artisan ibkr:tickle             # keep gateway session alive (runs via scheduler)
php artisan prices:prune            # delete snapshots past the retention window (runs daily)
```

## IBKR Client Portal Gateway setup

### 1. Download the gateway

Download from: https://www.interactivebrokers.com/en/trading/ib-api.php
→ Scroll to "Client Portal API" → download the ZIP.

Unzip to a permanent location, e.g. `~/Tools/clientportal.gw/`.

### 2. Configure for paper trading

Edit `root/conf.yaml` inside the unzipped folder:

```yaml
listenPort: 5001   # paper uses 5001, live uses 5000
```

### 3. Start the gateway

```bash
cd ~/Tools/clientportal.gw
bin/run.sh root/conf.yaml
```

The gateway starts on `https://localhost:5001`. It uses a self-signed TLS certificate —
the app connects with `verify => false`.

### 4. Authenticate

Open `https://localhost:5001` in a browser, log in with your IBKR credentials, and select
your **paper trading account**. The session lasts ~20 minutes; the `ibkr:tickle` command
(scheduled every 15 minutes) keeps it alive.

If the session drops (e.g. after a Mac sleep), use the "Re-authenticate" button on the
dashboard or the "IBKR gateway not authenticated" banner.

### 5. Set your account ID in .env

Find your paper account ID in the IBKR gateway UI (format: `DU1234567`).

```
IBKR_PAPER_ACCOUNT_ID=DU1234567
```

Live account IDs use the `U` prefix (e.g. `U1234567`). Set `IBKR_LIVE_ACCOUNT_ID` when
ready to go live, then switch `IBKR_MODE=live`.

### 6. Import positions

```bash
php artisan ibkr:import-positions
```

This pulls your current positions from the broker and upserts them into the `positions` table,
including the IBKR `conid` needed for price syncing.

### 7. Enable the scheduler

Add to crontab (`crontab -e`):

```
* * * * * cd /Users/robbinthijssen/Projects/stocks/web && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs:
- `ibkr:sync-prices` — every minute
- `ibkr:evaluate-rules` — every minute
- `ibkr:sync-orders` — every 2 minutes
- `ibkr:tickle` — every 15 minutes
- `queue:work --stop-when-empty` — every minute, delivers queued order notifications
- `prices:prune` — daily at 02:15

## Routes

### Public (no auth)

| Route | Controller | Notes |
|-------|-----------|-------|
| `GET /login` | `Auth\LoginController@showForm` | |
| `POST /login` | `Auth\LoginController@login` | Throttled: 5 per minute per email + IP |
| `GET /two-factor-challenge` | `Auth\TwoFactorChallengeController@show` | Only with a pending login in session |
| `POST /two-factor-challenge` | `Auth\TwoFactorChallengeController@store` | TOTP or recovery code, throttled |

### Protected (behind `auth` middleware)

| Route | Controller | Notes |
|-------|-----------|-------|
| `POST /logout` | `Auth\LoginController@logout` | |
| `GET /` | `DashboardController@index` | Portfolio overview, IBKR auth banner |
| `GET /positions` | `PositionController@index` | |
| `GET /positions/create` | `PositionController@create` | |
| `POST /positions` | `PositionController@store` | |
| `GET /positions/{id}` | `PositionController@show` | Price sparkline, order history |
| `GET /positions/{id}/edit` | `PositionController@edit` | |
| `PUT /positions/{id}` | `PositionController@update` | |
| `DELETE /positions/{id}` | `PositionController@destroy` | |
| `GET /rules` | `RuleController@index` | Global default + position rules |
| `GET /rules/create` | `RuleController@create` | `?position_id=` pre-selects a position |
| `POST /rules` | `RuleController@store` | `position_id` null = global default |
| `GET /rules/{id}/edit` | `RuleController@edit` | |
| `PUT /rules/{id}` | `RuleController@update` | |
| `DELETE /rules/{id}` | `RuleController@destroy` | |
| `GET /orders` | `OrderController@index` | Read-only, paginated |
| `GET /settings` | `SettingsController@index` | |
| `POST /settings/trading` | `SettingsController@updateTrading` | Kill switch: pause/resume all trading |
| `POST /settings/dry-run` | `SettingsController@updateDryRun` | Toggle dry run |
| `POST /settings/sync-prices` | `SettingsController@syncPrices` | Manual trigger |
| `POST /settings/evaluate-rules` | `SettingsController@evaluateRules` | Manual trigger |
| `POST /settings/sync-orders` | `SettingsController@syncOrders` | Manual trigger |
| `POST /settings/two-factor` | `TwoFactorController@store` | Begin 2FA enrolment |
| `POST /settings/two-factor/confirm` | `TwoFactorController@confirm` | Confirm with a TOTP code |
| `POST /settings/two-factor/recovery-codes` | `TwoFactorController@recoveryCodes` | Regenerate recovery codes |
| `DELETE /settings/two-factor` | `TwoFactorController@destroy` | Turn 2FA off |
| `POST /settings/api-tokens` | `ApiTokenController@store` | |
| `DELETE /settings/api-tokens/{token}` | `ApiTokenController@destroy` | |
| `POST /ibkr/reauth` | `SettingsController@reauth` | Re-authenticate gateway session |

## Architecture

### Models

| Model | Notes |
|-------|-------|
| `Position` | Symbol, quantity, avg buy price, IBKR conid. `gainPct(float): float`. `last_triggered_at` holds the cooldown. `forActiveAccount()` scope limits queries to the account `IBKR_MODE` resolves to. |
| `Rule` | Take-profit %, stop-loss %, cooldown length. `isInCooldown(Position): bool` — the window is per position, the rule only supplies its length. `position_id` null = global default. One rule per position (unique index), one global rule (validated). |
| `Order` | Append-only order log. Statuses: `pending → placed → filled / cancelled / failed`, plus `simulated` for dry-run records. |
| `PriceSnapshot` | Append-only. No `updated_at`. Index on `(symbol, fetched_at)`. `isStale()` against `ibkr.max_price_age_minutes`. |
| `Setting` | Key/value store for runtime toggles: `trading_enabled` (default on), `dry_run` (default off). |
| `User` | Single admin user, created by seeder. Optional TOTP 2FA; secret and recovery codes encrypted at rest. |

### Services (`app/Services/`)

- **`IbkrClient`** — thin `Http::` wrapper for all Client Portal API calls. Reads `config('ibkr.mode')` in constructor to pick the right gateway URL and account ID. All calls use `verify => false` (self-signed cert) and explicit short timeouts.
- **`IbkrAuthService`** — session keepalive (`tickle`), status check, reauthentication with polling. The status is cached briefly so a page render never waits on the gateway.
- **`OrderNotifier`** — logs every order event, then dispatches a queued notification. Swallows dispatch failures so a mail outage cannot take a scheduled run down.
- **`TwoFactorAuthenticator`** — TOTP secrets, verification, QR code SVG and single-use recovery codes.

### Actions (`app/Actions/`)

- **`SyncPricesAction`** — fetches prices in batches of 50 conids, for the active account only. Retries once on empty snapshot response (IBKR quirk: first call returns `{}`).
- **`EvaluateRulesAction`** — the safety gate. In order it: stops if trading is paused, stops if the gateway session is down, skips positions outside the active account, skips positions with no quantity left, skips positions with an order still in flight, skips stale prices, then fires `PlaceOrderAction` when a threshold is crossed and the position is not in cooldown. Stamps the cooldown on the position.
- **`PlaceOrderAction`** — places a market order via IBKR. Handles the confirmation-challenge response shape (`messageIds`). Checks every response and only records `placed` once a non-empty broker order id comes back; anything else is `failed` with the reason. Writes a `simulated` record and skips the gateway entirely during a dry run.
- **`SyncOrderStatusAction`** — polls `GET /v1/api/iserver/account/orders`, updates `placed` orders to `filled` or `cancelled`, and applies a fill to the position quantity so the same holding is never sold twice.
- **`ImportPositionsFromIbkrAction`** — upserts positions from `GET /v1/api/portfolio/{accountId}/positions/0`.

### Scheduler (`routes/console.php`)

```php
Schedule::command('ibkr:sync-prices')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:evaluate-rules')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:sync-orders')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('ibkr:tickle')->everyFifteenMinutes();
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();
Schedule::command('prices:prune')->dailyAt('02:15');
```

`withoutOverlapping()` is critical — prevents double-triggering rules if a cycle runs slow.

## Caching

The only cached value is the IBKR gateway auth status, held for
`IBKR_AUTH_STATUS_TTL_SECONDS` (default 10) so repeated dashboard renders and the per-minute
rule evaluation share one answer. `IbkrAuthService::reauthenticate()` clears and repopulates it.

SQLite WAL mode is enabled in `AppServiceProvider::boot()` to handle concurrent scheduler
writes without lock errors.

## Safety controls

Two runtime switches live in the `settings` table and are toggled from the settings page:

- **Trading enabled** (default on) — the kill switch. `EvaluateRulesAction` returns early when
  off. Price and order-status sync keep running so the portfolio view stays accurate.
- **Dry run** (default off) — triggered rules record a `simulated` order instead of calling the
  gateway. Cooldown behaves exactly as in a real run, and a simulated order blocks further
  evaluation of that position *while dry run is on*, standing in for the sale that would have
  closed it. Once dry run is off those records are history and block nothing.

Both are surfaced as banners on the dashboard whenever they are not in their normal state.

## Buy sizing

A sell is self-sizing: the position says how much is held. A buy is not, and the app has no
notion of cash or buying power, so a percentage of it cannot be computed.

The model chosen is **a fixed cash amount per trigger**, in the position currency, clamped by
whatever headroom is left under the rule's `max_position_value`. A share count was rejected
because it ages badly as the price moves, and a percentage of buying power because it needs an
account balance nothing currently fetches.

`max_position_value` is required whenever `buy_below_pct` is set. An uncapped buy rule keeps
firing all the way down, which is how a small mistake becomes a large one.

When a sell and a buy would both trigger at the same price, the sell wins.

## Paper vs live trading

Controlled by a single `.env` variable:

```
IBKR_MODE=paper   # or: live
```

`IbkrClient` reads this once in its constructor and routes all calls to the correct gateway
URL and account ID. Paper account IDs start with `DU`; live account IDs start with `U`.

Keep `account_mode` and `broker_account_id` on the `positions` table accurate. They let you
hold both paper and live positions in the same database during the transition, and price sync
and rule evaluation both scope to the account `IBKR_MODE` currently resolves to, so a stale
paper row can never be traded against the live account.

## Configuration

Beyond the gateway settings above:

| Env | Default | What it does |
|-----|---------|--------------|
| `IBKR_MAX_PRICE_AGE_MINUTES` | 5 | How old a price may be and still be traded on |
| `IBKR_TIMEOUT_SECONDS` | 10 | Total timeout on every gateway call |
| `IBKR_CONNECT_TIMEOUT_SECONDS` | 3 | Connect timeout on every gateway call |
| `IBKR_AUTH_STATUS_TTL_SECONDS` | 10 | How long the session status is cached |
| `STOCKS_SNAPSHOT_RETENTION_DAYS` | 30 | Price snapshot retention; 0 keeps everything |
| `STOCKS_NOTIFICATIONS_ENABLED` | true | Master switch for order notifications |
| `STOCKS_NOTIFICATION_CHANNELS` | mail | Comma separated notification channels |
| `STOCKS_NOTIFICATION_EVENTS` | placed,filled,failed | Which order events notify |

## Key gotchas

1. **IBKR snapshot first-call quirk** — `GET /v1/api/iserver/marketdata/snapshot` returns `{}`
   or `"not subscribed"` on the first request for a new conid. `SyncPricesAction` detects this
   and retries once after a 1-second sleep.

2. **Order confirmation challenge** — `POST /v1/api/iserver/account/{id}/orders` sometimes
   returns `{"id": "...", "messageIds": [...]}` instead of placing the order directly. `PlaceOrderAction`
   detects this and calls `POST /v1/api/iserver/reply/{replyId}` automatically.

3. **Gateway session** — expires after ~20 minutes. The tickle command (every 15 min) keeps
   it alive. If the Mac sleeps or the gateway restarts, the session is lost. The dashboard
   banner makes this visible immediately; "Re-authenticate" calls `IbkrAuthService::reauthenticate()`.

4. **Conid required for price sync** — `positions.ibkr_con_id` must be set for a position to
   appear in price sync. `ImportPositionsFromIbkrAction` populates it automatically. For
   manually-added positions, look up the conid via the IBKR contract search endpoint or the
   gateway UI, then set it on the edit form.

5. **Crypto symbols** — IBKR uses `secType: CRYPTO` and symbol format `BTC.USD`. Set
   `market = CRYPTO` on the position; the conid search endpoint accepts `secType` as a param.

6. **Stale prices are not traded on** — price sync stops silently when the session drops, so
   rule evaluation ignores any snapshot older than `IBKR_MAX_PRICE_AGE_MINUTES` (default 5) and
   refuses to run at all without an authenticated session. Both show on the dashboard.

7. **Pre-live checklist** — before setting `IBKR_MODE=live`:
   - Run for a week with dry run on and read back the simulated orders
   - Review cooldown values on all rules
   - Confirm no leftover paper positions are still in the table (the dashboard warns about them)
   - Turn on two-factor authentication in settings

## Testing

```bash
php artisan test
php artisan test --filter EvaluateRulesActionTest
```

- `RefreshDatabase` in `TestCase`
- `Http::fake()` for all IBKR calls — never hit the real gateway in tests, and
  `Http::preventStrayRequests()` in every action test so an unfaked call fails loudly
- `tests/Support/IbkrFakeResponses.php` — canned gateway payloads including the quirks: the
  confirmation challenge, the first-call snapshot with no price, the auth failure
- `fakeIbkrAuth()` in `tests/Pest.php` — rule evaluation needs a live session before it does
  anything, so register this first; `Http::fake()` merges stubs and the first match wins

## Linear

Team: **THI** (Thijssen Software) — `3b1bf7b2-5ff4-4e70-9ca5-a1efb1280839`

Branch format: `feature/thi-{number}-{description}` or `fix/thi-{number}-{description}`

Follow the full workflow in `~/.claude/CLAUDE.md`. See parent context in `~/Projects/stocks/CLAUDE.md`.
