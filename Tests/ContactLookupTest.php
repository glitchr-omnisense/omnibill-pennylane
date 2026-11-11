<?php

namespace Omnibill\Pennylane\Tests;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Gateway;
use Omnibill\Model\Address;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Pennylane\PennylaneGatewayFactory;
use Omnibill\Request\Accounting\FindContact;
use Omnisense\Contracts\Testing\MockHttp;
use PHPUnit\Framework\TestCase;

/**
 * Since omnibill 1.3: a contact whose creation's answer was lost, found
 * again by its external_reference. Answers written from Pennylane's Company
 * API v2 reference (GET /suppliers, GET /customers: external_reference
 * among their filters), read on 2026-11-11: no account, no call left.
 */
final class ContactLookupTest extends TestCase
{
    private const URL = 'https://app.pennylane.com/api/external/v2';

    private static function gateway(MockHttp $http): Gateway
    {
        $gateway = (new PennylaneGatewayFactory($http->client))->create(['token' => 'pl-company-token']);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    private static function edf(): Contact
    {
        return new Contact(ContactKind::SUPPLIER, 'EDF', 'supplier:edf', '401EDF', siren: '552081317', address: new Address('22-30 avenue de Wagram', '75008', 'Paris'));
    }

    public function testASupplierWhoseCreationsAnswerWasLostIsFoundByItsExternalReference(): void
    {
        $lost = MockHttp::routes(['~^POST https://app\.pennylane\.com/api/external/v2/suppliers$~' => [504, '']]);
        try {
            self::gateway($lost)->saveContact(self::edf());
            self::fail();
        } catch (OutcomeUnknownException) {
            self::assertCount(1, $lost->requests, 'not sent again');
        }

        $http = MockHttp::routes(['~^GET https://app\.pennylane\.com/api/external/v2/suppliers\?~' => [200, '{"items":['.file_get_contents(__DIR__.'/Fixtures/supplier.json').'],"has_more":false,"next_cursor":null}']]);
        $gateway = self::gateway($http);
        self::assertTrue($gateway->supports(FindContact::class));
        $found = $gateway->findContact(self::edf());

        self::assertSame(['3301', 'supplier:edf', '401EDF', ContactKind::SUPPLIER], [$found?->reference, $found?->key, $found?->accountNumber, $found?->kind]);
        self::assertSame(['GET '.self::URL.'/suppliers?limit=100&filter=[{"field":"external_reference","operator":"eq","value":"supplier:edf"}]'], array_map(urldecode(...), $http->lines()));
        self::assertSame('Bearer pl-company-token', $http->header('authorization'));
    }

    public function testACustomerIsAskedForOnTheCustomersAndNoneIsAnAnswer(): void
    {
        $http = MockHttp::routes(['~^GET https://app\.pennylane\.com/api/external/v2/customers\?~' => static fn (string $method, string $url) => str_contains(urldecode($url), '"value":"lease-42"')
            ? [200, '@'.__DIR__.'/Fixtures/customers.json']
            : [200, '{"items":[],"has_more":false,"next_cursor":null}']]);
        $gateway = self::gateway($http);

        $tenant = $gateway->findContact(new Contact(ContactKind::CUSTOMER, 'Camille Érable', 'lease-42', company: false));
        self::assertSame(['2201', 'lease-42', false], [$tenant?->reference, $tenant?->key, $tenant?->company]);
        self::assertNull($gateway->findContact(new Contact(ContactKind::CUSTOMER, 'Personne', 'lease-43')));
        self::assertStringStartsWith('GET '.self::URL.'/customers?', $http->lines()[1]);
    }

    public function testAContactWithoutAKeyCannotBeFound(): void
    {
        $http = MockHttp::routes([]);
        try {
            self::gateway($http)->findContact(new Contact(ContactKind::SUPPLIER, 'EDF'));
            self::fail();
        } catch (InvalidConfigException $e) {
            self::assertSame('The contact "EDF" has no key to be found by.', $e->getMessage());
            self::assertSame([], $http->requests, 'nothing asked');
        }
    }
}
