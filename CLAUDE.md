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

## Routes

### Public (no auth)

| Route | Controller | Notes |
|-------|-----------|-------|
| `GET /login` | `Auth\LoginController@showForm` | |
| `POST /login` | `Auth\LoginController@login` | |

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
| `POST /settings/sync-prices` | `SettingsController@syncPrices` | Manual trigger |
| `POST /settings/evaluate-rules` | `SettingsController@evaluateRules` | Manual trigger |
| `POST /settings/sync-orders` | `SettingsController@syncOrders` | Manual trigger |
| `POST /ibkr/reauth` | `SettingsController@reauth` | Re-authenticate gateway session |

## Architecture

### Models

| Model | Notes |
|-------|-------|
| `Position` | Symbol, quantity, avg buy price, IBKR conid. `gainPct(float): float` helper. |
| `Rule` | Take-profit %, stop-loss %, cooldown. `isInCooldown(): bool`. `position_id` null = global default. |
| `Order` | Append-only order log. Statuses: `pending → placed → filled / cancelled / failed`. |
| `PriceSnapshot` | Append-only. No `updated_at`. Index on `(symbol, fetched_at)`. |
| `User` | Single admin user, created by seeder. |

### Services (`app/Services/`)

- **`IbkrClient`** — thin `Http::` wrapper for all Client Portal API calls. Reads `config('ibkr.mode')` in constructor to pick the right gateway URL and account ID. All calls use `verify => false` (self-signed cert).
- **`IbkrAuthService`** — session keepalive (`tickle`), status check, reauthentication with polling.

### Actions (`app/Actions/`)

- **`SyncPricesAction`** — fetches prices in batches of 50 conids. Retries once on empty snapshot response (IBKR quirk: first call returns `{}`).
- **`EvaluateRulesAction`** — for each active position, finds the position-level rule or falls back to the global default. Fires `PlaceOrderAction` when TP or SL threshold is crossed and the rule is not in cooldown.
- **`PlaceOrderAction`** — places a market order via IBKR. Handles the confirmation-challenge response shape (`messageIds`). Creates an `Order` record; marks it `failed` on exception.
- **`SyncOrderStatusAction`** — polls `GET /v1/api/iserver/account/orders` and updates `placed` orders to `filled` or `cancelled`.
- **`ImportPositionsFromIbkrAction`** — upserts positions from `GET /v1/api/portfolio/{accountId}/positions/0`.

### Scheduler (`routes/console.php`)

```php
Schedule::command('ibkr:sync-prices')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:evaluate-rules')->everyMinute()->withoutOverlapping();
Schedule::command('ibkr:sync-orders')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('ibkr:tickle')->everyFifteenMinutes();
```

`withoutOverlapping()` is critical — prevents double-triggering rules if a cycle runs slow.

## Caching

No application-level cache. SQLite WAL mode is enabled in `AppServiceProvider::boot()` to
handle concurrent scheduler writes without lock errors.

## Paper vs live trading

Controlled by a single `.env` variable:

```
IBKR_MODE=paper   # or: live
```

`IbkrClient` reads this once in its constructor and routes all calls to the correct gateway
URL and account ID. Paper account IDs start with `DU`; live account IDs start with `U`.

Keep `account_mode` on the `positions` table accurate — it lets you hold both paper and live
positions in the same database during the transition period.

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

6. **Pre-live checklist** — before setting `IBKR_MODE=live`:
   - Audit `PlaceOrderAction` — double-check order quantity and side logic
   - Review cooldown values on all rules
   - Consider adding 2FA to the app login

## Testing

```bash
php artisan test
php artisan test --filter EvaluateRulesActionTest
```

- `RefreshDatabase` in `TestCase`
- `Http::fake()` for all IBKR calls — never hit the real gateway in tests
- `tests/Support/IbkrFakeResponses.php` — canned JSON payloads (to be built out as tests expand)

## Linear

Team: **THI** (Thijssen Software) — `3b1bf7b2-5ff4-4e70-9ca5-a1efb1280839`

Branch format: `feature/thi-{number}-{description}` or `fix/thi-{number}-{description}`

Follow the full workflow in `~/.claude/CLAUDE.md`. See parent context in `~/Projects/stocks/CLAUDE.md`.
