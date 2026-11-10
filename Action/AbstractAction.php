<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Action\ActionInterface;
use Omnibill\Action\ApiAwareInterface;
use Omnibill\Action\ApiAwareTrait;
use Omnibill\Exception\ProviderException;
use Omnibill\Model\Address;
use Omnibill\Model\Amount;
use Omnibill\Model\Contact;
use Omnibill\Model\ContactKind;
use Omnibill\Model\EntryLine;
use Omnibill\Model\EntryStatus;
use Omnibill\Model\JournalEntry;
use Omnibill\Model\VatKind;
use Omnibill\Pennylane\Api;

/** An action on Pennylane's Company API v2: journals and ledger accounts by code and number, the readings back. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /**
     * A ledger entry's status, as the family's: a draft, or waiting for the
     * accountant (accounting_needed, validation_needed, waiting_details), is
     * DRAFT; complete or entry is POSTED; archived is CANCELED.
     */
    public const STATUSES = [
        'draft' => EntryStatus::DRAFT, 'accounting_needed' => EntryStatus::DRAFT, 'validation_needed' => EntryStatus::DRAFT, 'waiting_details' => EntryStatus::DRAFT,
        'complete' => EntryStatus::POSTED, 'entry' => EntryStatus::POSTED, 'archived' => EntryStatus::CANCELED,
    ];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /** @return array<string, int> the journals' ids by code */
    protected function journals(): array
    {
        $ids = [];
        foreach ($this->api->all('/journals') as $journal) {
            if (null !== ($code = self::string($journal, 'code')) && null !== ($id = self::int($journal, 'id'))) {
                $ids[$code] = $id;
            }
        }

        return $ids;
    }

    protected function journal(string $code): int
    {
        return $this->journals()[$code] ?? throw new ProviderException(Api::PROVIDER, \sprintf('No journal with the code %s.', $code), 'not_found');
    }

    /**
     * The ledger account a line goes on: by its number; where Pennylane has
     * several under one number (one per VAT rate: 706000 at FR_200, at
     * FR_100...), the one whose vat_rate is the line's.
     */
    protected function account(EntryLine $line): int
    {
        $candidates = array_values(array_filter(
            Api::items($this->api->get('/ledger_accounts', ['limit' => 100], [['number', 'eq', $line->account]])),
            static fn (array $a) => false !== ($a['enabled'] ?? true),
        ));
        if (!$candidates) {
            throw new ProviderException(Api::PROVIDER, \sprintf('No ledger account with the number %s.', $line->account), 'not_found');
        }
        $rate = self::vatRate($line);
        foreach ($candidates as $account) {
            if (null !== $rate && $rate === self::string($account, 'vat_rate') && null !== ($id = self::int($account, 'id'))) {
                return $id;
            }
        }

        return self::int($candidates[0], 'id') ?? throw new ProviderException(Api::PROVIDER, 'A ledger account without an id.');
    }

    /** A line's VAT as Pennylane names a rate on an account: FR_200 for 20 %, exempt. */
    protected static function vatRate(EntryLine $line): ?string
    {
        if (null === $line->vat) {
            return null;
        }

        return VatKind::EXEMPT === $line->vat->kind ? 'exempt' : 'FR_'.intdiv($line->vat->rate, 10);
    }

    /**
     * A ledger entry as answered: its id, piece number, status, date, lines.
     *
     * @param array<string, mixed> $answer
     * @param array<int, string>   $codes  the journals' codes by id, when known
     */
    protected static function read(JournalEntry $entry, array $answer, array $codes = [], bool $lines = true): JournalEntry
    {
        $read = [];
        foreach (Api::items(['items' => $answer['ledger_entry_lines'] ?? []]) as $line) {
            $account = self::map($line, 'ledger_account');
            $read[] = new EntryLine(self::string($account, 'number') ?? '', self::cents($line, 'debit'), self::cents($line, 'credit'), self::string($line, 'label'));
        }
        $journal = self::int(self::map($answer, 'journal'), 'id') ?? self::int($answer, 'journal_id');

        return $entry->with(
            reference: self::string($answer, 'id'),
            number: self::string($answer, 'piece_number'),
            status: self::STATUSES[self::string($answer, 'status') ?? ''] ?? EntryStatus::UNKNOWN,
            lines: $lines && $read ? $read : null,
            piece: self::string($answer, 'piece_number'),
            date: null !== ($date = self::string($answer, 'date')) ? new \DateTimeImmutable($date) : null,
            journal: null !== $journal ? ($codes[$journal] ?? null) : null,
            label: self::string($answer, 'label'),
            data: $answer,
        );
    }

    /**
     * A customer or a supplier as answered.
     *
     * @param array<string, mixed> $answer
     */
    protected static function contact(ContactKind $kind, array $answer, ?Contact $given = null): Contact
    {
        $address = self::map($answer, ContactKind::SUPPLIER === $kind ? 'postal_address' : 'billing_address');
        $emails = \is_array($answer['emails'] ?? null) ? array_values(array_filter($answer['emails'], 'is_string')) : [];
        $name = self::string($answer, 'name') ?? trim((self::string($answer, 'first_name') ?? '').' '.(self::string($answer, 'last_name') ?? ''));

        return new Contact(
            $kind,
            $name,
            self::string($answer, 'external_reference') ?? $given?->key,
            self::string(self::map($answer, 'ledger_account'), 'number') ?? $given?->accountNumber,
            $emails[0] ?? null,
            self::string($answer, 'reg_no'),
            self::string($answer, 'establishment_no'),
            self::string($answer, 'vat_number'),
            self::string($answer, ContactKind::SUPPLIER === $kind ? 'iban' : 'billing_iban'),
            null !== self::string($address, 'address') ? new Address((string) self::string($address, 'address'), self::string($address, 'postal_code') ?? '', self::string($address, 'city') ?? '', self::string($address, 'country_alpha2') ?? 'FR') : null,
            'individual' !== self::string($answer, 'customer_type') && null === self::string($answer, 'first_name'),
            self::string($answer, 'id'),
            $answer,
        );
    }

    /** @param array<string, mixed> $row */
    protected static function cents(array $row, string $key): int
    {
        $value = $row[$key] ?? '0';

        return \is_string($value) || \is_int($value) || \is_float($value) ? Amount::minor('' === $value ? '0' : $value) : 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    protected static function map(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $map = [];
        foreach (\is_array($value) ? $value : [] as $k => $v) {
            $map[(string) $k] = $v;
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    protected static function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : (\is_int($value) ? (string) $value : null);
    }

    /** @param array<string, mixed> $row */
    protected static function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
