<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Request\Accounting\SaveContact;
use Omnibill\Request\Request;

/**
 * A customer: POST /company_customers (a person: /individual_customers, the
 * name split into first and last) - its billing address required by
 * Pennylane; a supplier: POST /suppliers. PUT on the same paths with the
 * reference. The application's key is the external_reference, the
 * auxiliary account the ledger_account's number.
 */
final class SaveContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof SaveContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SaveContact);
        $contact = $request->contact;
        $path = self::path($contact);
        $answer = $this->api->send(null === $contact->reference ? 'POST' : 'PUT', $path.(null !== $contact->reference ? '/'.rawurlencode($contact->reference) : ''), self::body($contact));
        $request->setResult(self::contact($contact->kind, $answer, $contact));
    }

    private static function path(Contact $contact): string
    {
        return ContactKind::SUPPLIER === $contact->kind ? '/suppliers' : ($contact->company ? '/company_customers' : '/individual_customers');
    }

    /** @return array<string, mixed> */
    private static function body(Contact $contact): array
    {
        $address = null !== $contact->address ? ['address' => $contact->address->line, 'postal_code' => $contact->address->postalCode, 'city' => $contact->address->city, 'country_alpha2' => $contact->address->country] : null;
        $common = [
            'emails' => null !== $contact->email ? [$contact->email] : null,
            'external_reference' => $contact->key,
            'ledger_account' => null !== $contact->accountNumber ? ['number' => $contact->accountNumber] : null,
        ];
        if (ContactKind::SUPPLIER === $contact->kind) {
            return array_filter(['name' => $contact->name, 'reg_no' => $contact->siren, 'establishment_no' => $contact->siret, 'vat_number' => $contact->vatNumber, 'iban' => $contact->iban, 'postal_address' => $address] + $common, static fn ($v) => null !== $v);
        }
        if (null === $address) {
            throw new InvalidConfigException(\sprintf('Pennylane needs a billing address for the customer "%s".', $contact->name));
        }
        $names = $contact->company ? ['name' => $contact->name] : (static function (string $name): array {
            $parts = explode(' ', trim($name), 2);

            return ['first_name' => $parts[0], 'last_name' => $parts[1] ?? $parts[0]];
        })($contact->name);

        return array_filter($names + ($contact->company ? ['vat_number' => $contact->vatNumber, 'reg_no' => $contact->siren] : []) + ['billing_address' => $address, 'billing_iban' => $contact->iban] + $common, static fn ($v) => null !== $v);
    }
}
