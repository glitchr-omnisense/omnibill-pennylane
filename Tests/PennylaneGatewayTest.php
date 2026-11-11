<?php

namespace Omnibill\Pennylane\Tests;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Exception\ProviderException;
use Omnibill\Gateway;
use Omnibill\Model\Address;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Model\EntryLine;
use Omnibill\Model\EntryStatus;
use Omnibill\Model\JournalEntry;
use Omnibill\Model\Vat;
use Omnibill\Model\VatKind;
use Omnibill\Pennylane\OAuth;
use Omnibill\Pennylane\PennylaneGatewayFactory;
use Omnibill\Request\Submit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Pennylane's Company API v2 reference (the OpenAPI
 * its pages carry: ledger entries, journals, ledger accounts, customers,
 * suppliers), its guides (cursor pagination, OAuth 2.0, the 2026 API
 * changes) and its API contract terms, read on 2026-11-10: no account was
 * used, no call left.
 */
final class PennylaneGatewayTest extends TestCase
{
    private const URL = 'https://app.pennylane.com/api/external/v2';

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $calls = [];

    /** @param list<string> $answers fixture[:status] */
    private function http(array $answers): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$method.' '.$url);
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status, 'response_headers' => ['content-type' => 'application/json']]);
        });
    }

    /**
     * @param list<string>         $answers
     * @param array<string, mixed> $options
     */
    private function gateway(array $answers, array $options = []): Gateway
    {
        $gateway = (new PennylaneGatewayFactory($this->http($answers)))->create($options + ['token' => 'pl-company-token']);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    /** @return array{string, string, mixed} method, URL (decoded), JSON body */
    private function call(int $n): array
    {
        $body = $this->calls[$n][2]['body'] ?? '';

        return [$this->calls[$n][0], urldecode($this->calls[$n][1]), json_decode(\is_string($body) ? $body : '', true)];
    }

    private function header(int $call, string $name): ?string
    {
        foreach ((array) ($this->calls[$call][2]['headers'] ?? []) as $line) {
            [$n, $value] = explode(': ', \is_string($line) ? $line : '', 2);
            if (0 === strcasecmp($n, $name)) {
                return $value;
            }
        }

        return null;
    }

    private static function bill(): JournalEntry
    {
        return new JournalEntry('AC', new \DateTimeImmutable('2026-11-10'), 'Électricité novembre', [
            new EntryLine('615', 10000, 0, vat: new Vat(2000, VatKind::DEDUCTIBLE, code: 'A20')),
            new EntryLine('44566', 2000, 0, 'TVA 20 %', vat: new Vat(2000, VatKind::DEDUCTIBLE, 10000, 'A20')),
            new EntryLine('401EDF', 0, 12000, 'Électricité novembre', 'supplier:edf'),
        ], 'EDF-2026-11', 'bill:edf-2026-11');
    }

    public function testALedgerEntryIsPostedOnItsJournalAndTheAccountOfItsVatRate(): void
    {
        $posted = $this->gateway(['journals', 'accounts-615', 'accounts-44566', 'accounts-401EDF', 'entry-created:201'])->postEntry(self::bill());

        self::assertSame(['GET', self::URL.'/journals?limit=100'], \array_slice($this->call(0), 0, 2));
        self::assertSame(['GET', self::URL.'/ledger_accounts?limit=100&filter=[{"field":"number","operator":"eq","value":"615"}]'], \array_slice($this->call(1), 0, 2));
        self::assertSame(['POST', self::URL.'/ledger_entries', [
            'date' => '2026-11-10', 'label' => 'Électricité novembre', 'journal_id' => 8002, 'currency' => 'EUR', 'piece_number' => 'EDF-2026-11',
            'ledger_entry_lines' => [
                ['debit' => '100.00', 'credit' => '0.00', 'ledger_account_id' => 5151],
                ['debit' => '20.00', 'credit' => '0.00', 'ledger_account_id' => 4456, 'label' => 'TVA 20 %'],
                ['debit' => '0.00', 'credit' => '120.00', 'ledger_account_id' => 4011, 'label' => 'Électricité novembre'],
            ],
        ]], $this->call(4));
        self::assertSame('Bearer pl-company-token', $this->header(4, 'Authorization'));
        self::assertNull($this->header(4, 'X-Company-Id'));

        self::assertSame(['610042', 'EDF-2026-11', EntryStatus::DRAFT, 'AC', 'bill:edf-2026-11'], [$posted->reference, $posted->number, $posted->status, $posted->journal, $posted->key]);
        self::assertSame('supplier:edf', $posted->lines[2]->contact, 'the lines given are kept');
    }

    public function testAFirmTokenNamesTheCompany(): void
    {
        $this->gateway(['customers'], ['company_id' => '42', 'token' => 'pl-firm-token'])->contacts(ContactKind::CUSTOMER);

        self::assertSame(['Bearer pl-firm-token', '42'], [$this->header(0, 'Authorization'), $this->header(0, 'X-Company-Id')]);
    }

    public function testWhatPennylaneHasNotIsSaid(): void
    {
        try {
            $this->gateway(['journals'])->postEntry(new JournalEntry('OD', new \DateTimeImmutable(), 'x', [new EntryLine('512', 100, 0), new EntryLine('471', 0, 100)]));
            self::fail();
        } catch (ProviderException $e) {
            self::assertStringContainsString('No journal with the code OD', $e->getMessage());
        }
        $this->expectExceptionMessage('No ledger account with the number 512');
        $this->gateway(['journals', 'accounts-none'])->postEntry(new JournalEntry('BQ', new \DateTimeImmutable(), 'x', [new EntryLine('512', 100, 0), new EntryLine('471', 0, 100)]));
    }

    public function testAnEntryIsReadBackWithItsLines(): void
    {
        $entry = $this->gateway(['entry', 'journals'])->fetchEntry(new JournalEntry('?', new \DateTimeImmutable(), '?', reference: '610042'));

        self::assertSame(['GET', self::URL.'/ledger_entries/610042'], \array_slice($this->call(0), 0, 2));
        self::assertSame(['AC', EntryStatus::POSTED, 'Électricité novembre', '2026-11-10'], [$entry->journal, $entry->status, $entry->label, $entry->date->format('Y-m-d')]);
        self::assertSame([['401EDF', 0, 12000], ['615', 10000, 0], ['44566', 2000, 0]], array_map(static fn ($l) => [$l->account, $l->debit, $l->credit], $entry->lines), 'in Pennylane\'s order, which is not guaranteed');
        self::assertTrue($entry->isBalanced());
    }

    public function testEntriesArePagedByCursor(): void
    {
        $list = $this->gateway(['journals', 'entries'])->entries(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 'AC', 'abc', 2);

        self::assertSame(['GET', self::URL.'/ledger_entries?limit=2&cursor=abc&sort=-date&filter=[{"field":"date","operator":"gteq","value":"2026-11-01"},{"field":"date","operator":"lteq","value":"2026-11-30"},{"field":"journal_id","operator":"eq","value":8002}]'], \array_slice($this->call(1), 0, 2));
        self::assertSame([['610042', 'AC', EntryStatus::POSTED], ['610017', 'VT', EntryStatus::DRAFT]], array_map(static fn ($e) => [$e->reference, $e->journal, $e->status], $list->entries));
        self::assertSame('eyJpZCI6NjEwMDE3fQ', $list->cursor);
    }

    public function testASupplierAndACustomerAreSaved(): void
    {
        $gateway = $this->gateway(['supplier:201', 'supplier', 'customer:201', 'customer']);
        $edf = new Contact(ContactKind::SUPPLIER, 'EDF', 'supplier:edf', '401EDF', 'factures@edf.example', '552081317', '55208131766522', 'FR03552081317', 'FR7630004000031234567890143', new Address('22-30 avenue de Wagram', '75008', 'Paris'));
        $saved = $gateway->saveContact($edf);

        self::assertSame(['POST', self::URL.'/suppliers', [
            'name' => 'EDF', 'reg_no' => '552081317', 'establishment_no' => '55208131766522', 'vat_number' => 'FR03552081317', 'iban' => 'FR7630004000031234567890143',
            'postal_address' => ['address' => '22-30 avenue de Wagram', 'postal_code' => '75008', 'city' => 'Paris', 'country_alpha2' => 'FR'],
            'emails' => ['factures@edf.example'], 'external_reference' => 'supplier:edf', 'ledger_account' => ['number' => '401EDF'],
        ]], $this->call(0));
        self::assertSame(['3301', '401EDF'], [$saved->reference, $saved->accountNumber]);
        $gateway->saveContact($saved);
        self::assertSame(['PUT', self::URL.'/suppliers/3301'], \array_slice($this->call(1), 0, 2));

        $tenant = $gateway->saveContact(new Contact(ContactKind::CUSTOMER, 'Camille Érable', 'lease-42', '411U3', 'camille@erable.example', address: new Address('3 rue des Érables', '67000', 'Strasbourg'), company: false));
        self::assertSame(['POST', self::URL.'/individual_customers', [
            'first_name' => 'Camille', 'last_name' => 'Érable',
            'billing_address' => ['address' => '3 rue des Érables', 'postal_code' => '67000', 'city' => 'Strasbourg', 'country_alpha2' => 'FR'],
            'emails' => ['camille@erable.example'], 'external_reference' => 'lease-42', 'ledger_account' => ['number' => '411U3'],
        ]], $this->call(2));
        self::assertSame(['2201', false, 'Camille Érable'], [$tenant->reference, $tenant->company, $tenant->name]);

        $read = $gateway->fetchContact($tenant);
        self::assertSame(['GET', self::URL.'/customers/2201'], \array_slice($this->call(3), 0, 2));
        self::assertSame(['lease-42', 'Strasbourg'], [$read->key, $read->address?->city]);

        $this->expectException(InvalidConfigException::class);
        $gateway->saveContact(new Contact(ContactKind::CUSTOMER, 'Sans adresse'));
    }

    public function testContactsAreListedByKindAndName(): void
    {
        $list = $this->gateway(['customers'])->contacts(ContactKind::CUSTOMER, 'Cam', limit: 10);

        self::assertSame(['GET', self::URL.'/customers?limit=10&filter=[{"field":"name","operator":"start_with","value":"Cam"}]'], \array_slice($this->call(0), 0, 2));
        self::assertSame([['2201', 'lease-42']], array_map(static fn ($c) => [$c->reference, $c->key], $list->contacts));
        self::assertNull($list->cursor);
    }

    public function testAnEntryWhoseOutcomeIsUnknownIsRecognisedByWhatItCarries(): void
    {
        try {
            $this->gateway(['journals', 'accounts-615', 'accounts-44566', 'accounts-401EDF', 'error-503:503'])->postEntry(self::bill());
            self::fail();
        } catch (OutcomeUnknownException $e) {
            self::assertSame('503', $e->providerCode, 'POST /ledger_entries may have been taken: not posted again blindly');
        }

        $this->calls = [];
        $gateway = $this->gateway(['journals', 'entries-day', 'journals', 'entries-day']);
        $found = $gateway->findEntry(self::bill());
        self::assertSame(['GET', self::URL.'/ledger_entries?sort=id&limit=100&filter=[{"field":"date","operator":"eq","value":"2026-11-10"},{"field":"journal_id","operator":"eq","value":8002}]'], \array_slice($this->call(1), 0, 2));
        self::assertSame(['610042', 'EDF-2026-11', 'bill:edf-2026-11'], [$found?->reference, $found?->number, $found?->key]);
        $other = new JournalEntry('AC', new \DateTimeImmutable('2026-11-10'), 'Électricité novembre', [], 'EDF-2026-11B', 'bill:edf-2026-11b');
        self::assertNull($gateway->findEntry($other), 'the same label, another piece');
    }

    public function testErrors(): void
    {
        try {
            $this->gateway(['error-401:401'])->contacts(ContactKind::SUPPLIER);
            self::fail();
        } catch (InvalidKeyException $e) {
            self::assertStringContainsString('The access token is invalid', $e->getMessage());
        }
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Entry lines are not balanced');
        $this->gateway(['journals', 'accounts-615', 'accounts-44566', 'accounts-401EDF', 'error-422:422'])->postEntry(self::bill());
    }

    public function testAPartnersAppAuthorizesExchangesAndRefreshes(): void
    {
        $oauth = new OAuth($this->http(['oauth-token', 'oauth-invalid-grant:400']), 'pl-client', 'pl-secret');

        self::assertSame('https://app.pennylane.com/oauth/authorize?client_id=pl-client&redirect_uri=https%3A%2F%2Fmeyers.example%2Fpennylane&response_type=code&scope=ledger_entries%3Aall%20ledger_accounts%3Areadonly%20journals%3Areadonly%20customers%3Aall%20suppliers%3Aall&state=s1', $oauth->authorizeUrl('https://meyers.example/pennylane', 's1'));
        self::assertSame(['access_token' => 'pl-access-1', 'refresh_token' => 'pl-refresh-1', 'expires_in' => 86400], $oauth->exchange('code-1', 'https://meyers.example/pennylane'));
        self::assertSame(['POST', 'https://app.pennylane.com/oauth/token'], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame('client_id=pl-client&client_secret=pl-secret&grant_type=authorization_code&code=code-1&redirect_uri=https%3A%2F%2Fmeyers.example%2Fpennylane', $this->calls[0][2]['body']);
        try {
            $oauth->refresh('pl-refresh-0');
            self::fail();
        } catch (InvalidKeyException $e) {
            self::assertSame('invalid_grant', $e->providerCode);
        }
    }

    public function testItKeepsBooksUnderPennylanesContractTerms(): void
    {
        $gateway = $this->gateway([]);
        $capabilities = $gateway->capabilities();

        self::assertSame([Capabilities::JOURNAL_ENTRIES, Capabilities::CONTACTS], $capabilities->contracts);
        self::assertStringContainsString('Internal use only', implode(' ', $capabilities->terms->rules ?? []));
        self::assertSame('https://pennylane.readme.io/page/api-contract-terms', $capabilities->terms?->source);
        self::assertFalse($gateway->supports(Submit::class));
    }
}
