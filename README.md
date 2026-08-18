# Migrate Cloudflare Rules — Laravel Artisan command

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/migrate-cloudflare-rules.svg?style=flat-square)](https://packagist.org/packages/padosoft/migrate-cloudflare-rules)
[![Tests](https://img.shields.io/github/actions/workflow/status/padosoft/migrate-cloudflare-rules/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/padosoft/migrate-cloudflare-rules/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/migrate-cloudflare-rules.svg?style=flat-square)](https://packagist.org/packages/padosoft/migrate-cloudflare-rules)
[![License](https://img.shields.io/github/license/padosoft/migrate-cloudflare-rules.svg?style=flat-square)](LICENSE)

`padosoft/migrate-cloudflare-rules` adds a single Artisan command, `cloudflare:migrate`, to your Laravel 12+ application.
The command **copies Cloudflare security configuration from one zone/account (the *source*) to another zone/account (the *destination*)** using the Cloudflare REST API v4:

- WAF custom rules (formerly *Firewall Rules*)
- IP Access Rules — both **account-level** and **zone-level**
- User Agent Blocking rules
- Rate Limiting rules
- Custom Lists (IP lists) together with their items
- Page Rules, with automatic rewriting of the source domain into the destination domain

It is the tool you want when you move a website to a new Cloudflare account, split a zone into two, clone the security posture of a production zone onto a staging zone, or simply want to keep two zones aligned without clicking through the dashboard for hours.

The command was written for a real migration between two Cloudflare accounts, has been used in production against the live API, and comes with a test-suite that exercises every rule type against a faked Cloudflare API.

> **Heads-up about the Cloudflare API.** Cloudflare has deprecated some of the legacy endpoints this command talks to (Firewall Rules API, the previous-generation Rate Limiting API, and the Page Rules product). Everything is documented in [Cloudflare API status and compatibility notes](#cloudflare-api-status-and-compatibility-notes) — please read that section before relying on the `waf`, `ratelimit` and `pagerules` types.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Environment variables](#environment-variables)
  - [Publishing the config file](#publishing-the-config-file)
  - [Where to find your Account ID and Zone ID](#where-to-find-your-account-id-and-zone-id)
  - [How to create the API tokens (and which permissions they need)](#how-to-create-the-api-tokens-and-which-permissions-they-need)
  - [Which values are needed for which rule type](#which-values-are-needed-for-which-rule-type)
- [Usage](#usage)
  - [Arguments](#arguments)
  - [Options](#options)
  - [Recommended workflow](#recommended-workflow)
- [Examples](#examples)
- [What the command does, rule type by rule type](#what-the-command-does-rule-type-by-rule-type)
  - [`waf` — WAF custom rules](#waf--waf-custom-rules)
  - [`ipaccessrulesaccount` / `ipaccessruleszone` — IP Access Rules](#ipaccessrulesaccount--ipaccessruleszone--ip-access-rules)
  - [`useragent` — User Agent Blocking rules](#useragent--user-agent-blocking-rules)
  - [`ratelimit` — Rate Limiting rules](#ratelimit--rate-limiting-rules)
  - [`customlists` — Custom Lists](#customlists--custom-lists)
  - [`pagerules` — Page Rules](#pagerules--page-rules)
- [Duplicate detection](#duplicate-detection)
- [Dry run and debug output](#dry-run-and-debug-output)
- [Error handling, plan limits and exit codes](#error-handling-plan-limits-and-exit-codes)
- [Cloudflare API status and compatibility notes](#cloudflare-api-status-and-compatibility-notes)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2 or newer |
| Laravel | 12.x or 13.x (`illuminate/console`, `illuminate/http`, `illuminate/support`) |
| Guzzle | 7.8+ (pulled in automatically, it is what the Laravel HTTP client uses) |
| Cloudflare | Two API tokens (one for the source, one for the destination) with the permissions listed below |

The command runs entirely from the CLI (`php artisan …`); it does not register routes, views, migrations or middleware.

## Installation

Install the package with Composer:

```bash
composer require padosoft/migrate-cloudflare-rules
```

Laravel's package auto-discovery registers the service provider (`Padosoft\MigrateCloudflareRules\MigrateCloudflareRulesServiceProvider`) automatically. If you have disabled auto-discovery, add it manually to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    Padosoft\MigrateCloudflareRules\MigrateCloudflareRulesServiceProvider::class,
];
```

Verify that the command is available:

```bash
php artisan list cloudflare
# cloudflare
#   cloudflare:migrate  Migrate Cloudflare security rules (WAF custom rules, IP Access Rules, ...) from a source zone/account to a destination zone/account.

php artisan help cloudflare:migrate   # full built-in help, with all the examples
```

## Configuration

Credentials are **never** passed on the command line (they would end up in your shell history and be visible to other users through `ps`). They are read from the package configuration file, which in turn reads them from your `.env` file.

### Environment variables

Add these variables to the `.env` file of your Laravel application (a ready-to-copy `.env.example` ships with the package):

```dotenv
# SOURCE: the account/zone the rules are READ from
CLOUDFLARE_MIGRATE_SOURCE_API_TOKEN=
CLOUDFLARE_MIGRATE_SOURCE_ACCOUNT_ID=
CLOUDFLARE_MIGRATE_SOURCE_ZONE_ID=

# DESTINATION: the account/zone the rules are CREATED in
CLOUDFLARE_MIGRATE_DESTINATION_API_TOKEN=
CLOUDFLARE_MIGRATE_DESTINATION_ACCOUNT_ID=
CLOUDFLARE_MIGRATE_DESTINATION_ZONE_ID=
```

| Variable | Description |
|----------|-------------|
| `CLOUDFLARE_MIGRATE_SOURCE_API_TOKEN` | API token that can **read** the source account/zone. |
| `CLOUDFLARE_MIGRATE_SOURCE_ACCOUNT_ID` | Account ID of the source account (needed by account-level resources). |
| `CLOUDFLARE_MIGRATE_SOURCE_ZONE_ID` | Zone ID of the source zone (needed by zone-level resources). |
| `CLOUDFLARE_MIGRATE_DESTINATION_API_TOKEN` | API token that can **edit** the destination account/zone. |
| `CLOUDFLARE_MIGRATE_DESTINATION_ACCOUNT_ID` | Account ID of the destination account. |
| `CLOUDFLARE_MIGRATE_DESTINATION_ZONE_ID` | Zone ID of the destination zone. |

Migrating between two zones **of the same account**? Use the same token and the same account ID on both sides and just change the zone IDs.

If any value required by the selected rule type is missing, the command stops immediately with a message such as:

```
Missing Cloudflare configuration: migrate-cloudflare-rules.source.api_token, migrate-cloudflare-rules.destination.zone_id.
Set the corresponding CLOUDFLARE_MIGRATE_* variables in your .env file (or publish and edit config/migrate-cloudflare-rules.php).
```

and exits with code `1`, without contacting Cloudflare.

### Publishing the config file

Publishing is optional — the package ships with sensible defaults that read the variables above. Publish it only if you want to rename the environment variables or hard-wire values from another source:

```bash
php artisan vendor:publish --tag=migrate-cloudflare-rules-config
```

This copies the file to `config/migrate-cloudflare-rules.php`:

```php
return [
    'source' => [
        'api_token'  => env('CLOUDFLARE_MIGRATE_SOURCE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_MIGRATE_SOURCE_ACCOUNT_ID'),
        'zone_id'    => env('CLOUDFLARE_MIGRATE_SOURCE_ZONE_ID'),
    ],
    'destination' => [
        'api_token'  => env('CLOUDFLARE_MIGRATE_DESTINATION_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_MIGRATE_DESTINATION_ACCOUNT_ID'),
        'zone_id'    => env('CLOUDFLARE_MIGRATE_DESTINATION_ZONE_ID'),
    ],
];
```

### Where to find your Account ID and Zone ID

Both identifiers are 32-character hexadecimal strings and are **not secrets** (they are, however, needed to build the API URLs).

**Zone ID**

1. Log in to the [Cloudflare dashboard](https://dash.cloudflare.com/).
2. Select the account, then click the domain (zone) you are interested in.
3. On the zone **Overview** page scroll down the right-hand sidebar to the **API** box: it shows **Zone ID** and **Account ID**, each with a *Click to copy* link.

**Account ID**

- Same **API** box on any zone Overview page, **or**
- look at the URL of your browser after selecting the account: `https://dash.cloudflare.com/<ACCOUNT_ID>/…` — the first path segment is the Account ID, **or**
- Dashboard → *Manage Account* → *Configurations* (the Account ID is displayed there as well).

You need the source values for the zone/account you are copying **from** and the destination values for the zone/account you are copying **to**.

### How to create the API tokens (and which permissions they need)

Use **API tokens**, not the legacy Global API Key: tokens can be scoped to the minimum set of permissions and to specific accounts/zones, and can be revoked individually.

1. Dashboard → click your profile icon (top right) → **My Profile** → **API Tokens** (direct link: <https://dash.cloudflare.com/profile/api-tokens>).
2. Click **Create Token** → **Create Custom Token** → *Get started*.
3. Give the token a descriptive name (e.g. `migrate-rules SOURCE (read-only)`).
4. Add the permissions from the table below. Use **Read** for the source token and **Edit** for the destination token.
5. Under **Account Resources** and **Zone Resources** restrict the token to the specific account and zone (*Include → Specific zone → your zone*). Never leave it on *All zones* if you can avoid it.
6. Optionally restrict **Client IP Address Filtering** to the IP of the machine running the migration and set a **TTL**.
7. *Continue to summary* → *Create Token* → copy the token **now** (it is shown only once) and paste it into your `.env`.
8. Repeat for the destination token.

Permissions needed, per rule type (names as displayed in the Cloudflare token editor):

| Rule type (`{type}` argument) | Source token (Read) | Destination token (Edit) |
|-------------------------------|---------------------|--------------------------|
| `waf` | Zone → **Firewall Services** (legacy Firewall Rules API used to *read* the source rules and the destination rules for duplicate detection) and Zone → **Zone WAF** (Rulesets API used to *write* custom rules) | Zone → **Zone WAF** *(Edit)*, Zone → **Firewall Services** *(Read is enough — used only for duplicate detection)* |
| `ipaccessruleszone` | Zone → **Firewall Services** | Zone → **Firewall Services** |
| `ipaccessrulesaccount` | Account → **Account Firewall Access Rules** | Account → **Account Firewall Access Rules** |
| `useragent` | Zone → **Firewall Services** | Zone → **Firewall Services** |
| `ratelimit` | Zone → **Firewall Services** | Zone → **Firewall Services** |
| `customlists` | Account → **Account Filter Lists** | Account → **Account Filter Lists** |
| `pagerules` | Zone → **Page Rules** | Zone → **Page Rules** |

If you plan to migrate everything, a convenient minimal pair of tokens is:

- **Source token (Read):** Zone → Firewall Services: Read, Zone → Zone WAF: Read, Zone → Page Rules: Read, Account → Account Firewall Access Rules: Read, Account → Account Filter Lists: Read.
- **Destination token (Edit):** the same list with **Edit** instead of Read (Firewall Services: Edit, Zone WAF: Edit, Page Rules: Edit, Account Firewall Access Rules: Edit, Account Filter Lists: Edit).

You can verify a token before using it:

```bash
curl -s "https://api.cloudflare.com/client/v4/user/tokens/verify" \
  -H "Authorization: Bearer $CLOUDFLARE_MIGRATE_SOURCE_API_TOKEN" | jq .
# {"result":{"id":"...","status":"active"},"success":true,...}
```

### Which values are needed for which rule type

API tokens are always required. Account IDs and zone IDs are validated depending on the type:

| `{type}` | Needs `*_ACCOUNT_ID` | Needs `*_ZONE_ID` |
|----------|:--------------------:|:-----------------:|
| `waf` | | ✔ |
| `ipaccessruleszone` | | ✔ |
| `ipaccessrulesaccount` | ✔ | |
| `useragent` | | ✔ |
| `ratelimit` | | ✔ |
| `customlists` | ✔ | |
| `pagerules` | | ✔ |

## Usage

```
php artisan cloudflare:migrate {type} {mode}
    [--source-url=] [--destination-url=]
    [--exclude=ID]... [--only_rules_id=ID]...
    [--dryrun] [--debug]
```

### Arguments

| Argument | Values | Description |
|----------|--------|-------------|
| `type` | `waf`, `ipaccessrulesaccount`, `ipaccessruleszone`, `useragent`, `ratelimit`, `customlists`, `pagerules` | Which kind of rules to migrate. One type per invocation — run the command once per type. |
| `mode` | `bulk`, `individual` | `bulk` migrates every rule found in the source in one pass, ignoring `--exclude` / `--only_rules_id`. `individual` migrates the rules one by one and honours `--exclude` and `--only_rules_id`. Both modes create rules **one API call at a time** and both skip rules that already exist in the destination — see [Duplicate detection](#duplicate-detection). For `waf`, `customlists` and `pagerules` the two modes behave identically: they always iterate rule by rule and always honour the filters. |

### Options

| Option | Applies to | Description |
|--------|-----------|-------------|
| `--exclude=ID` | `individual` mode (always for `waf`, `customlists`, `pagerules`) | Skip the rule with this **source** ID. Repeatable: `--exclude=a --exclude=b`. For `customlists` the value is the **list ID**. |
| `--only_rules_id=ID` | `individual` mode (always for `waf`, `customlists`, `pagerules`) | Migrate **only** the rules with these **source** IDs (repeatable). Rules not in the list are reported as *"not included in the given list, skipped"*. For `customlists` the value is the **list name** (not the ID). |
| `--source-url=…` | `pagerules` (required) | Domain (or any substring of the URL) of the source zone, e.g. `example.com`. |
| `--destination-url=…` | `pagerules` (required) | Domain (or substring) that replaces `--source-url` in every Page Rule target, e.g. `example.org`. |
| `--dryrun` | all | Do **not** create anything on Cloudflare. Reads from the source (and from the destination for duplicate detection) as usual, then prints the HTTP request that *would* have been sent for each rule. |
| `--debug` | all | Print every HTTP request and response exchanged with Cloudflare (method, URL, headers, body). The API token is masked. |

`--exclude` and `--only_rules_id` can be combined; a rule must pass both filters to be migrated.

### Recommended workflow

1. Configure the `.env` variables and verify the tokens.
2. Run a **dry run** first and read the output carefully:
   ```bash
   php artisan cloudflare:migrate waf individual --dryrun
   ```
3. Run the real migration for the same type:
   ```bash
   php artisan cloudflare:migrate waf individual
   ```
4. Re-running the same command is safe: rules that already exist in the destination are detected and skipped, so you can run it again after fixing an error and only the missing rules will be created.
5. Repeat for the other types you need. A typical full migration is:
   ```bash
   php artisan cloudflare:migrate customlists individual        # lists first: WAF rules may reference them
   php artisan cloudflare:migrate ipaccessrulesaccount bulk
   php artisan cloudflare:migrate ipaccessruleszone bulk
   php artisan cloudflare:migrate useragent bulk
   php artisan cloudflare:migrate waf individual
   php artisan cloudflare:migrate ratelimit bulk
   php artisan cloudflare:migrate pagerules individual --source-url=example.com --destination-url=example.org
   ```

## Examples

Migrate all WAF custom rules:

```bash
php artisan cloudflare:migrate waf bulk
```

Migrate WAF rules one by one, skipping two of them:

```bash
php artisan cloudflare:migrate waf individual --exclude=372e67954025e0ba6aaa6d586b9e0b59 --exclude=8ff5c1d2e2b64ad4a3d3ffb1a1c9d7e0
```

Migrate **only** two specific WAF rules:

```bash
php artisan cloudflare:migrate waf individual --only_rules_id=372e67954025e0ba6aaa6d586b9e0b59 --only_rules_id=8ff5c1d2e2b64ad4a3d3ffb1a1c9d7e0
```

Migrate the account-level IP Access Rules (e.g. blocked countries / IP ranges shared by all zones):

```bash
php artisan cloudflare:migrate ipaccessrulesaccount bulk
```

Migrate the zone-level IP Access Rules, excluding one:

```bash
php artisan cloudflare:migrate ipaccessruleszone individual --exclude=92f17202ed8bd63d69a66b86a49a8f6b
```

Migrate the User Agent Blocking rules:

```bash
php artisan cloudflare:migrate useragent bulk
```

Migrate the Rate Limiting rules:

```bash
php artisan cloudflare:migrate ratelimit bulk
```

Migrate the Custom Lists (and their IP items). Note that for lists `--only_rules_id` takes the list **name**:

```bash
php artisan cloudflare:migrate customlists individual
php artisan cloudflare:migrate customlists individual --only_rules_id=office_ips --only_rules_id=partners
```

Migrate the Page Rules from `example.com` to `example.org` — every occurrence of `example.com` inside the rule targets becomes `example.org`:

```bash
php artisan cloudflare:migrate pagerules individual --source-url=example.com --destination-url=example.org
```

Simulate anything without touching Cloudflare, with the full HTTP conversation printed:

```bash
php artisan cloudflare:migrate ipaccessruleszone bulk --dryrun --debug
```

Sample output of a real run:

```
Starting migration of ipaccessruleszone rules in individual mode...
Fetching destination rules page 1 of 1 ...
Loaded all 12 ipaccessruleszone rules from the destination account.
Found 12 ipaccessruleszone rules in the destination account.
Fetching ipaccessruleszone page 1 of 1 ...
Loaded all 3 ipaccessruleszone rules from the source account.
Found 3 ipaccessruleszone rules in the source account.
Migrating ipaccessruleszone rule 1 of 3 with "Office" ruleid=1f6b…...
Rule id 1f6b… already exists in the destination account with rule id 9c0a…
Rule 1f6b… already exists, skipped.
Migrating ipaccessruleszone rule 2 of 3 with "Scanner" ruleid=7ad2…...
Rule id: 7ad2… created successfully.
Rule 7ad2… migrated successfully.
Migrating ipaccessruleszone rule 3 of 3 with "" ruleid=b3e0…...
Rule id: b3e0… created successfully.
Rule b3e0… migrated successfully.
```

## What the command does, rule type by rule type

Common flow for every type:

1. Load the credentials from the config and validate them for the selected type.
2. Download **all** the existing rules of that type from the **destination** (paginated, 50 per page). They are used for duplicate detection. *(For `customlists` the destination lists are loaded right before migrating.)*
3. Download **all** the rules of that type from the **source** (paginated).
4. For each source rule: apply `--only_rules_id` / `--exclude` (individual mode), check whether an equivalent rule already exists in the destination, otherwise build the payload and `POST` it to the destination.
5. Print a line per rule and write errors to the Laravel log (`Log::error`).

The **source rule ID is never reused**: it is stripped from the payload, because IDs are unique per account/zone. The destination assigns new IDs.

### `waf` — WAF custom rules

- Source rules are read with `GET /zones/{source_zone}/firewall/rules` (legacy Firewall Rules API — see the [API notes](#cloudflare-api-status-and-compatibility-notes)).
- Destination rules are written with the **Rulesets API** as *WAF custom rules*:
  - the command looks for the zone's ruleset with `phase = http_request_firewall_custom` and `kind = zone` (`GET /zones/{dest_zone}/rulesets`);
  - if it exists, each rule is appended with `POST /zones/{dest_zone}/rulesets/{ruleset_id}/rules`;
  - if it does not exist yet, the first rule creates it with `POST /zones/{dest_zone}/rulesets` (`name: "Custom WAF Ruleset"`, `kind: zone`, `phase: http_request_firewall_custom`).
- Payload mapping: `filter.expression` → `expression`, `description` → `description`, `paused` → `enabled = !paused`, `action` → `action`.
- The legacy **`allow` action does not exist in custom rules: it is converted to `skip`**, with `action_parameters` that skip the remaining rules of the current ruleset, the phases `http_ratelimit`, `http_request_sbfm`, `http_request_firewall_managed` and the products `zoneLockdown`, `uaBlock`, `bic`, `hot`, `securityLevel`, `rateLimit`, `waf`. This reproduces the "allow = bypass everything else" semantics of the old firewall rules. Review the resulting *skip* rules in the destination dashboard afterwards if you need a narrower bypass.
- Rules with any other action (`block`, `challenge`, `managed_challenge`, `js_challenge`, `log`, …) are sent as they are.
- Duplicate detection: a source rule is skipped when a destination rule has the **same expression *or* the same description**.

### `ipaccessrulesaccount` / `ipaccessruleszone` — IP Access Rules

- `ipaccessrulesaccount` reads from `GET /accounts/{source_account}/firewall/access_rules/rules` and writes to `POST /accounts/{dest_account}/firewall/access_rules/rules`.
- `ipaccessruleszone` reads from `GET /zones/{source_zone}/firewall/access_rules/rules` and writes to `POST /zones/{dest_zone}/firewall/access_rules/rules`.
- Payload: `configuration.target` (`ip`, `ip_range`, `asn`, `country`), `configuration.value`, `mode` (`block`, `challenge`, `whitelist`, `js_challenge`, `managed_challenge`; defaults to `block`), `notes` (defaults to *"IP Access rule created by migration"*).
- Duplicate detection: same `configuration.target` **and** same `configuration.value`.

### `useragent` — User Agent Blocking rules

- Reads from `GET /zones/{source_zone}/firewall/ua_rules`, writes to `POST /zones/{dest_zone}/firewall/ua_rules`.
- Payload: the source rule as returned by the API, minus its `id` (`mode`, `configuration.target = ua`, `configuration.value`, `description`, `paused`).
- Duplicate detection: same `description` **and** same `mode`.

### `ratelimit` — Rate Limiting rules

- Reads from `GET /zones/{source_zone}/rate_limits`, writes to `POST /zones/{dest_zone}/rate_limits` (previous-generation Rate Limiting API — **see the [API notes](#cloudflare-api-status-and-compatibility-notes): Cloudflare has retired this API**).
- Payload: the source rule as returned by the API, minus its `id`.
- Duplicate detection: same `match.request.url` **and** same `action`.

### `customlists` — Custom Lists

- Reads the lists with `GET /accounts/{source_account}/rules/lists`, creates each one with `POST /accounts/{dest_account}/rules/lists` (`name`, `kind`, `description`), then reads the items with `GET /accounts/{source_account}/rules/lists/{list_id}/items` and pushes them in a single call to `POST /accounts/{dest_account}/rules/lists/{new_list_id}/items`.
- Items are mapped as `{ "ip": …, "comment": … }` — i.e. the migration supports lists of kind **`ip`**. Lists of other kinds (`hostname`, `asn`, `redirect`) are created but their items are not converted (the `ip` field would be missing); migrate those items by hand or open a PR.
- Empty lists are created without calling the items endpoint (the API rejects an empty item array).
- Duplicate detection: a list is skipped when the destination already has a list with the same **name**.
- Filters: `--only_rules_id` matches the list **name**, `--exclude` matches the list **ID**.
- Migrate lists **before** WAF rules whose expressions reference them (`ip.src in $office_ips`), otherwise the destination will refuse those expressions.

### `pagerules` — Page Rules

- Reads from `GET /zones/{source_zone}/pagerules`, writes to `POST /zones/{dest_zone}/pagerules`.
- Payload: the source rule minus its `id` (`targets`, `actions`, `priority`, `status`).
- Because Page Rule targets contain URLs of the source domain (`*example.com/images/*`), every target `constraint.value` gets `--source-url` replaced with `--destination-url` (plain `str_replace`, so you can pass a full host, a bare domain or any substring). Both options are required for this type; if you really want a 1:1 copy between two zones serving the same hostname, pass the same value to both.
- Duplicate detection is **not implemented** for Page Rules (there is no description to compare): running the command twice creates the rules twice. Use `--dryrun` first and `--exclude` / `--only_rules_id` to re-run only what failed.

## Duplicate detection

Before creating anything, the command loads every rule of the selected type from the destination and compares each source rule against them:

| Type | Considered a duplicate when… |
|------|------------------------------|
| `waf` | same `filter.expression` **or** same `description` |
| `ipaccessrulesaccount`, `ipaccessruleszone` | same `configuration.target` **and** `configuration.value` |
| `useragent` | same `description` **and** `mode` |
| `ratelimit` | same `match.request.url` **and** `action` |
| `customlists` | same list `name` |
| `pagerules` | never (not implemented) |

Duplicates are reported as *"Rule id X already exists in the destination account with rule id Y"* and skipped. This is what makes the command **idempotent** for every type except `pagerules`.

## Dry run and debug output

`--dryrun` performs all the **read** calls (source rules, destination rules) but replaces every **write** call with a printout of the request:

```
Dry run enabled: the HTTP request will NOT be sent to Cloudflare, only printed.
=== HTTP REQUEST ===
Method: POST
URL: https://api.cloudflare.com/client/v4/zones/023e105f4ecef8ad9ca31a8372d0c353/firewall/access_rules/rules
Headers:
  Authorization: Bearer ************************************WXYZ
  Content-Type: application/json
Body:
{
    "configuration": {
        "target": "ip",
        "value": "198.51.100.4"
    },
    "mode": "block",
    "notes": "Scanner"
}
====================
```

`--debug` additionally prints every request **and** its response (status, headers, JSON body) — handy to understand a Cloudflare validation error. In both cases the bearer token is masked (only the last 4 characters are shown), so the output can be pasted into a ticket safely.

Everything the command prints goes to STDOUT through the normal Artisan output; errors are also written to the Laravel log channel (`Log::error`, `Log::debug` for the raw destination rules).

## Error handling, plan limits and exit codes

- A failed **write** (4xx/5xx from Cloudflare) is printed with the full JSON error returned by the API, logged, and the command **moves on to the next rule**.
- A failed **read of the destination rules** (needed for duplicate detection) aborts the whole run (`exit(1)`), because continuing could create duplicates.
- **Plan limits**: if Cloudflare answers `429`, or with an error containing *"exceeded the maximum number"* (the message returned when the destination plan cannot hold more rules of that type), the command prints *"Maximum number of rules reached for the account…"* and aborts immediately with exit code `1`. Buy more rules / upgrade the destination plan and re-run — already migrated rules will be skipped.
- Missing configuration → exit code `1`, nothing is called.
- Any other completed run exits with `0`, even if some rules failed: read the output (and the log) to spot the `Error while…` lines. Use `--only_rules_id` to retry just those rules.

## Cloudflare API status and compatibility notes

The command uses the Cloudflare **REST API v4** (`https://api.cloudflare.com/client/v4/`) — that is the current and only public version, it is not going away. Some of the *individual endpoints* it calls, however, belong to legacy products that Cloudflare has deprecated after this command was written and battle-tested. Status as of August 2026:

| Type | Endpoints used | Status | Modern replacement |
|------|----------------|--------|--------------------|
| `waf` (read side) | `GET /zones/{id}/firewall/rules` (source rules and destination rules for duplicate detection) | **Firewall Rules API and Filters API are deprecated and "no longer supported since 2025-06-15"** ([deprecations](https://developers.cloudflare.com/fundamentals/api/reference/deprecations/), [migration guide](https://developers.cloudflare.com/waf/reference/migration-guides/firewall-rules-to-custom-rules/)). Cloudflare had been translating calls to the Rulesets API internally; after the sunset date the endpoint may stop answering at any time. | Read the custom rules from the ruleset entry point: `GET /zones/{id}/rulesets/phases/http_request_firewall_custom/entrypoint` (rules carry `expression`, `action`, `description`, `enabled`). |
| `waf` (write side) | `GET/POST /zones/{id}/rulesets`, `POST /zones/{id}/rulesets/{ruleset_id}/rules` | ✅ Current (Rulesets API). | — |
| `ratelimit` | `GET/POST /zones/{id}/rate_limits` | **Rate Limiting API (previous version) is deprecated since 2025-06-15 and the API reference now states that these endpoints return `410 Gone`** ([API reference](https://developers.cloudflare.com/api/resources/rate_limits/methods/list/), [upgrade guide](https://developers.cloudflare.com/waf/reference/legacy/old-rate-limiting/upgrade/)). Expect the `ratelimit` type to fail against the live API. | Rulesets API, phase `http_ratelimit`: `GET /zones/{id}/rulesets/phases/http_ratelimit/entrypoint` to read, `PUT …/entrypoint` (or `POST /rulesets/{id}/rules`) to write rules with `expression`, `action` and a `ratelimit` object (`characteristics`, `period`, `requests_per_period`, `mitigation_timeout`, `counting_expression`). |
| `pagerules` | `GET/POST /zones/{id}/pagerules` | ⚠️ The **Page Rules product is deprecated**; Cloudflare is auto-migrating existing Page Rules to the new Rules products (2025 onward) and will retire them ([migration guide](https://developers.cloudflare.com/rules/reference/page-rules-migration/)). The API is not on the deprecation list yet and still works, but it will disappear together with the product. | Rulesets API phases: `http_request_dynamic_redirect` (Single Redirects), `http_request_cache_settings` (Cache Rules), `http_config_settings` (Configuration Rules), `http_request_origin` (Origin Rules), `http_request_transform` (Transform Rules). |
| `useragent` | `GET/POST /zones/{id}/firewall/ua_rules` | ✅ Supported (not deprecated). Cloudflare *recommends* custom rules instead, but the endpoint is alive. | Optional: WAF custom rules on `http.user_agent`. |
| `ipaccessruleszone`, `ipaccessrulesaccount` | `…/firewall/access_rules/rules` | ✅ Supported (not deprecated). Cloudflare *recommends* custom rules + lists instead. | Optional: WAF custom rules + Lists. |
| `customlists` | `/accounts/{id}/rules/lists`, `…/items` | ✅ Current (Lists API). | — |

**What this means in practice**

- `ipaccessrulesaccount`, `ipaccessruleszone`, `useragent`, `customlists` and the *write* side of `waf` use current, supported endpoints.
- `waf` still works as long as the legacy read endpoint keeps answering; a future release will read from the ruleset entry point instead (the payload written to the destination is already in the new format).
- `ratelimit` needs a port to the new rate limiting rules before it can be used again — the field mapping is documented in the table above and in Cloudflare's upgrade guide.
- `pagerules` works today; plan its replacement with Redirect/Cache/Configuration/Origin/Transform rules.

Contributions porting these types to the Rulesets API are very welcome (see [Contributing](#contributing)).

## Security notes

- Secrets live only in `.env` (or your secret manager) — the command never accepts them as CLI arguments and the config file reads them through `env()`.
- The bearer token is masked in every printout (`--dryrun`, `--debug`).
- Use two separate tokens: **read-only** for the source, **edit** for the destination, both restricted to the specific account/zone, ideally with an IP filter and an expiry.
- Everything is sent over HTTPS to `api.cloudflare.com` through the Laravel HTTP client (Guzzle).
- Revoke or let expire the tokens once the migration is done.

## Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `Missing Cloudflare configuration: …` | The `.env` variables listed in the message are empty. Run `php artisan config:clear` after editing `.env` if your config is cached. |
| `Cloudflare API error: … Status Code: 403 … "Authentication error"` / code `10000` | Wrong token, or the token lacks the permission for that resource / is not scoped to that zone/account. Check the [permissions table](#how-to-create-the-api-tokens-and-which-permissions-they-need) and verify the token with `/user/tokens/verify`. |
| `Status Code: 400 … "filter expression is invalid"` on `waf` | The expression references a list (`$name`) or a field not available in the destination (plan feature or list not migrated yet). Migrate `customlists` first / adjust the expression. |
| `Maximum number of rules reached for the account…` and exit code 1 | The destination plan cannot hold more rules of that type. Upgrade the plan or buy additional rules, then re-run: existing rules are skipped. |
| `Status Code: 410` on `ratelimit` | The legacy Rate Limiting API has been removed by Cloudflare — see the [API notes](#cloudflare-api-status-and-compatibility-notes). |
| Page Rules created twice | Duplicate detection is not implemented for `pagerules`. Delete the extra rules and use `--only_rules_id`/`--exclude` for partial re-runs. |
| `Error while fetching the destination … rules` and the command stops | The destination read failed (permissions, wrong zone ID, network). Nothing was created. |
| Nothing is printed for `Log::…` lines | They go to the Laravel log (`storage/logs/laravel.log` by default), not to the console. |

## Testing

The package ships with an [Orchestra Testbench](https://packages.tools/testbench) test-suite that boots a minimal Laravel application, fakes the Cloudflare API with `Http::fake()` (no network access, stray requests fail the test) and exercises every rule type, dry-run, filters, duplicate detection and configuration validation:

```bash
composer install
composer test        # or: vendor/bin/phpunit
```

CI runs the suite on PHP 8.2 / 8.3 / 8.4 against Laravel 12 and 13 (see `.github/workflows/run-tests.yml`).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Bug reports and pull requests are welcome on [GitHub](https://github.com/padosoft/migrate-cloudflare-rules). Ideas that would make great PRs:

- port the `waf` read side to `GET /zones/{id}/rulesets/phases/http_request_firewall_custom/entrypoint`;
- port `ratelimit` to the `http_ratelimit` ruleset phase;
- implement duplicate detection for `pagerules` (compare `targets` + `actions`);
- support items of non-`ip` Custom Lists (`hostname`, `asn`, `redirect`).

Please add tests (see `tests/Feature/MigrateCloudflareRulesCommandTest.php` for the fake-API helper) and keep the code formatted with `composer format` (Laravel Pint).

## Security vulnerabilities

If you discover a security vulnerability, please email `helpdesk@padosoft.com` instead of using the issue tracker.

## Credits

- [Lorenzo Padovani](https://github.com/padosoft)
- [Padosoft](https://www.padosoft.com)
- [All contributors](https://github.com/padosoft/migrate-cloudflare-rules/graphs/contributors)

The `array_getEx()` helper bundled in `src/helpers.php` is copied from [padosoft/support](https://github.com/padosoft/support) so that this package has no dependency on it.

## License

Apache License 2.0. Please see the [LICENSE](LICENSE) file for more information.
