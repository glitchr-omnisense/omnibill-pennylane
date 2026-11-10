<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\Amount;
use Omnibill\Request\Accounting\PostEntry;
use Omnibill\Request\Request;

/**
 * POST /ledger_entries: date, label, journal_id (the journal found by its
 * code), currency, piece_number, the lines with their amounts as decimal
 * strings and their ledger_account_id (found by number, and by VAT rate
 * where Pennylane has one account per rate). Pennylane's lines name no
 * third party: the auxiliary account (401EDF, 411U3) is the contact. Not
 * idempotent: posting twice records twice - the application keeps the
 * reference.
 */
final class PostEntryAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof PostEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof PostEntry);
        $entry = $request->entry;
        if (!$entry->isBalanced()) {
            throw new InvalidConfigException(\sprintf('The entry "%s" is not balanced: %d debit, %d credit.', $entry->label, $entry->totalDebit(), $entry->totalCredit()));
        }
        $journal = $this->journal($entry->journal);
        $lines = [];
        foreach ($entry->lines as $line) {
            $lines[] = array_filter([
                'debit' => Amount::decimal($line->debit),
                'credit' => Amount::decimal($line->credit),
                'ledger_account_id' => $this->account($line),
                'label' => $line->label,
            ], static fn ($v) => null !== $v);
        }
        $answer = $this->api->send('POST', '/ledger_entries', array_filter([
            'date' => $entry->date->format('Y-m-d'),
            'label' => $entry->label,
            'journal_id' => $journal,
            'currency' => $entry->currency,
            'piece_number' => $entry->piece,
            'ledger_entry_lines' => $lines,
        ], static fn ($v) => null !== $v));
        $request->setResult(self::read($entry, $answer, [$journal => $entry->journal], false));
    }
}
