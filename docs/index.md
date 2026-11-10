# omnibill/pennylane

Pennylane's accounting for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill) 1.1
and later: the `journal-entries` and `contacts` contracts (`JournalEntriesInterface`,
`ContactsInterface`), through Pennylane's Company API v2 on
`https://app.pennylane.com/api/external/v2` (`Api::URL`). Every call carries
`Authorization: Bearer <token>`; JSON both ways; lists paged by cursor (`limit`, `cursor`; answers
`{items, has_more, next_cursor}`); filters as a JSON array in the query
(`filter=[{"field":"number","operator":"eq","value":"615"}]`). The 2026 API changes are Pennylane's
only behaviour since 2026-07-01 (cursor pagination, `journal` and `ledger_account` objects in
answers); no version header is sent.

## Installation

```sh
composer require omnibill/pennylane
```

PHP 8.2 or later, `omnisense/omnibill` ^1.1 and `symfony/http-client`.

## The sources

Read on 2026-11-10: Pennylane's developer site (`pennylane.readme.io`): the Company API v2 reference
(the OpenAPI its pages carry: ledger entries and their lines, journals, ledger accounts, company and
individual customers, customers, suppliers), the guides (authentication, OAuth 2.0 walkthrough,
scopes, cursor pagination, rate limiting, error handling, the 2026 API changes, creating a ledger
entry) and the API contract terms (January 2024).

## Credentials

- A **company API token**: Settings → Connectivity → Developers, by an admin, on the Essential plan
  or above; one company per token, an expiry of 1, 6 or 12 months or none. Or a **firm token**, with
  the company's id in `company_id` (`X-Company-Id`).
- Or, for a **partner's app**, an OAuth 2.0 access token: the app (client id and secret) is issued by
  Pennylane's partnerships team. `Omnibill\Pennylane\OAuth` builds the authorization page
  (`/oauth/authorize`, the scopes the gateway needs: `ledger_entries:all ledger_accounts:readonly
  journals:readonly customers:all suppliers:all`), exchanges the code and refreshes the token
  (`/oauth/token`): an access token lasts 24 hours, a refresh token 90 days, each refresh replaces
  both - never refresh twice at once; `invalid_grant` means the company must authorize again.

Tokens go by reference (`%env(PENNYLANE_TOKEN)%`).

## Options

| Option | Default | |
|---|---|---|
| `token` | required | a company token, a firm token, or an OAuth access token |
| `company_id` | | `X-Company-Id`, with a firm token |
| `url` | | another API root |

## From the contracts to Pennylane

| | |
|---|---|
| `postEntry()` | `GET /journals` (the journal by `code`), `GET /ledger_accounts` per account (`number eq`; where Pennylane has one account per VAT rate under a number - 706000 at FR_200, at FR_100 - the one whose `vat_rate` is the line's VAT: `FR_200` for 20 %, `exempt`), then `POST /ledger_entries`: `date`, `label`, `journal_id`, `currency`, `piece_number`, `ledger_entry_lines` with `debit` and `credit` as decimal strings (`"100.00"`), `ledger_account_id`, `label` |
| `fetchEntry()` | `GET /ledger_entries/{id}` (its lines on `ledger_account.number`, in Pennylane's order, which is not guaranteed) and `GET /journals` for the code |
| `entries()` | `GET /ledger_entries` filtered by `date gteq`/`lteq` and `journal_id eq`, `sort=-date`, Pennylane's cursor |
| `saveContact()` | a customer: `POST /company_customers` (`name`, `vat_number`, `reg_no`: the SIREN), or for a person `POST /individual_customers` (`first_name`, `last_name`: the name split at its first space) - `billing_address` required; a supplier: `POST /suppliers` (`name`, `reg_no`, `establishment_no`: the SIRET, `vat_number`, `iban`, `postal_address`). Both: `emails`, `external_reference` (the key), `ledger_account.number` (the auxiliary account). `PUT` on the same path with the reference |
| `fetchContact()` | `GET /customers/{id}` or `/suppliers/{id}` |
| `contacts()` | `GET /customers` or `/suppliers`, `name start_with`, Pennylane's cursor |

| Pennylane `status` | `EntryStatus` |
|---|---|
| `draft`, `accounting_needed`, `validation_needed`, `waiting_details` | `DRAFT` - still the accountant's to complete |
| `complete`, `entry` | `POSTED` |
| `archived` | `CANCELED` |

`number` is Pennylane's `piece_number` (generated when none is given).

**VAT.** Pennylane's lines carry no VAT rate: the VAT is the ledger account's (`vat_rate`). The VAT
lines go on their accounts as given; a base line's VAT picks the account of its rate.

**Contacts on lines.** Pennylane's lines name no third party: the auxiliary account (401EDF, 411U3)
is the contact. `EntryLine::$contact` is kept on the entry, not sent.

**Not idempotent.** Creates are not idempotent: posting twice records twice. 25 requests per 5
seconds per token (a 429 is an `UnreachableException`; `retry-after` says when).

## Terms

`capabilities()->terms` (`PennylaneGatewayFactory::terms()`), from Pennylane's API contract terms:

- **Internal use only** (2.1): a Pennylane customer's own integration, with its own token. An
  integration that serves several Pennylane customers needs **API Partner** status and an OAuth app
  from Pennylane's partnerships team; its client id is never shared (3.1).
- Not redistributed nor resold; no suggested endorsement by Pennylane; no service competing with
  Pennylane's or circumventing its pricing (3.4 f, m, o).
- No copies of the data beyond what the intended use needs (3.4 p).
- Pennylane is granted a licence over the integrations and may ask for their source code (4.3).
- A company API token needs the Essential plan or above.

## Errors

| Answer | Exception |
|---|---|
| 401, 403 | `InvalidKeyException` (`The access token is invalid`, a missing scope) |
| other 4xx (`Entry lines are not balanced`, a missing field) | `ProviderException` (`error`, `message`, `field` read whichever shape came; `providerCode` the `code` when given) |
| a journal or ledger account not found; a customer without a billing address | `ProviderException` (`not_found`); `InvalidConfigException`, before the call |
| 429, 5xx, no answer | `UnreachableException` |

## Example in Symfony

```yaml
omnibill:
    gateways:
        accountant:
            factory: pennylane
            options:
                token: '%env(PENNYLANE_TOKEN)%'
```

```php
public function __construct(private readonly JournalEntriesInterface $accountant) {}   // ContactsInterface $accountant too
```

## Verified, and not

- Verified: every call above against a `MockHttpClient` that records the requests (method, URL with
  its filters, JSON body, headers) and serves answers written from Pennylane's OpenAPI
  (`Tests/Fixtures`); the OAuth exchange; PHPStan at level max.
- Not verified: no Pennylane account was used. The documents disagree on two points, read here as
  the walkthrough says: the host (`app.pennylane.com`, not `api.pennylane.com`) and the token path
  (`/oauth/token`, not `/oauth/oauth/token`); the exact error bodies.
- Next step: Pennylane is an approved e-invoicing platform (PA); its API sends a customer e-invoice
  to the PA (`POST /customer_invoices/{id}/send_to_pa`) and imports e-invoices - the family's
  `e-invoices` contract could be carried here, with the same token.
