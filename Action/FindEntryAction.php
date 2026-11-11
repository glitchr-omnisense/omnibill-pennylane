<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Request\Accounting\FindEntry;
use Omnibill\Request\Request;

/**
 * GET /ledger_entries filtered on the entry's date (eq) and journal_id
 * (eq), every page, sort=id; the one whose label is the entry's and,
 * when the entry has a piece, whose piece_number is it - the earliest when
 * several. Pennylane keeps no key of the application's on a ledger entry
 * (no external_reference, filters on id, date and journal_id only: its
 * Company API v2 reference, read 2026-11-11): the entry is recognised by
 * what it carries, and an entry without a piece by its label alone. Null
 * when none.
 */
final class FindEntryAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FindEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FindEntry);
        $entry = $request->entry;
        if (null === $entry->key && null === $entry->piece) {
            throw new InvalidConfigException(\sprintf('The entry "%s" has neither key nor piece to be found by.', $entry->label));
        }
        $journal = $this->journal($entry->journal);
        foreach ($this->api->all('/ledger_entries', ['sort' => 'id'], [['date', 'eq', $entry->date->format('Y-m-d')], ['journal_id', 'eq', $journal]]) as $item) {
            if ($entry->label === self::string($item, 'label') && (null === $entry->piece || $entry->piece === self::string($item, 'piece_number'))) {
                $request->setResult(self::read($entry, $item, [$journal => $entry->journal], false));

                return;
            }
        }
        $request->setResult(null);
    }
}
