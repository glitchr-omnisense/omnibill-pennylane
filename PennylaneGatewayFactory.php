<?php

namespace Omnibill\Pennylane;

use Omnibill\Config;
use Omnibill\GatewayFactory;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Terms;
use Omnibill\Pennylane\Action\FetchContactAction;
use Omnibill\Pennylane\Action\FetchEntryAction;
use Omnibill\Pennylane\Action\FindEntryAction;
use Omnibill\Pennylane\Action\ListContactsAction;
use Omnibill\Pennylane\Action\ListEntriesAction;
use Omnibill\Pennylane\Action\PostEntryAction;
use Omnibill\Pennylane\Action\SaveContactAction;
use Omnisense\Contracts\Policy\LegacyReset;
use Omnisense\Contracts\Policy\UsagePolicy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pennylane's accounting: ledger entries posted and read back, customers
 * and suppliers, through its Company API v2.
 *
 *   options:
 *     token: '%env(PENNYLANE_TOKEN)%'     # required: a company API token, a firm token, or a partner app's OAuth access token (OAuth)
 *     company_id: ~                       # X-Company-Id, with a firm token
 *     url: ~                              # another API root
 */
final class PennylaneGatewayFactory extends GatewayFactory
{
    private static ?UsagePolicy $policy = null;

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    public static function terms(): Terms
    {
        return new Terms(
            partner: false,
            commercial: true,
            price: 'a company API token needs Pennylane\'s Essential plan or above, and an admin',
            mustNotKeep: ['copies of Pennylane\'s data beyond what the intended use needs (contract terms 3.4 p)', 'the token in the code or the repository: by reference'],
            rules: [
                'Internal use only (contract terms 2.1): a company\'s own integration with its own token. An integration that serves several Pennylane customers needs API Partner status and an OAuth app from Pennylane\'s partnerships team; its client id is never shared (3.1)',
                'Neither redistributed nor resold, no suggested endorsement by Pennylane, no service competing with Pennylane\'s or circumventing its pricing (3.4 f, m, o)',
                'Pennylane is granted a licence over the integrations and may ask for their source code (4.3)',
                '25 requests per 5 seconds per token; creates are not idempotent',
                'Pennylane is also an approved e-invoicing platform (PA): its e-invoicing (send_to_pa, e-invoice import) is not carried by this package yet',
            ],
            source: 'https://pennylane.readme.io/page/api-contract-terms',
        );
    }

    /** Its usage policy, as policy.json declares it (read once). */
    public static function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(__DIR__.'/policy.json');
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibill.factory_name' => 'pennylane',
            'omnibill.factory_title' => 'Pennylane',
            'omnibill.required_options' => ['token'],
            'company_id' => null,
            'url' => null,
            'omnibill.capabilities' => new Capabilities([], approved: false, publicSector: false, contracts: [Capabilities::JOURNAL_ENTRIES, Capabilities::CONTACTS], terms: self::terms(), policy: self::usagePolicy()),
            'omnibill.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                self::text($c['token']),
                rtrim('' !== ($url = self::text($c->get('url'))) ? $url : Api::URL, '/'),
                '' !== ($company = self::text($c->get('company_id'))) ? $company : null,
                // ratelimit-reset is a Unix time: read with the other fields, a 429's wait when retry-after is missing.
                $c->backoff()?->withLegacyHeaders(LegacyReset::Epoch),
            ),
            'omnibill.action.post_entry' => new PostEntryAction(),
            'omnibill.action.fetch_entry' => new FetchEntryAction(),
            'omnibill.action.list_entries' => new ListEntriesAction(),
            'omnibill.action.find_entry' => new FindEntryAction(),
            'omnibill.action.save_contact' => new SaveContactAction(),
            'omnibill.action.fetch_contact' => new FetchContactAction(),
            'omnibill.action.list_contacts' => new ListContactsAction(),
        ]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
