# omnibill/pennylane

**Pennylane**'s accounting for [omnisense/omnibill](https://github.com/glitchr-omnisense/omnibill):
ledger entries posted and read back, customers and suppliers - the family's `journal-entries` and
`contacts` contracts - through Pennylane's **Company API v2**, with a company API token or a partner
app's OAuth token (an `OAuth` helper is included).

```php
use Omnibill\Pennylane\PennylaneGatewayFactory;

$books = (new PennylaneGatewayFactory($httpClient))->create(['token' => getenv('PENNYLANE_TOKEN')]);

$entry = $books->postEntry($journalEntry);   // POST /ledger_entries on the journal and accounts found by code and number
$books->saveContact($supplier);              // POST /suppliers
```

Written from Pennylane's API reference, guides and API contract terms, read on 2026-11-10. **Not
verified in real: no account.**

**Pennylane's API contract terms** allow a company's own integration with its own token (internal
use only); an integration that serves several Pennylane customers needs **API Partner** status and
an OAuth app from Pennylane - see [the docs](docs/index.md#terms).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Pennylane. It reaches their service on behalf of whoever runs it. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Pennylane's API contract
terms ([pennylane.readme.io/page/api-contract-terms](https://pennylane.readme.io/page/api-contract-terms)),
for the token it uses, and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT.
