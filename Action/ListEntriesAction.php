<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Exception\ProviderException;
use Omnibill\Model\EntryList;
use Omnibill\Model\JournalEntry;
use Omnibill\Pennylane\Api;
use Omnibill\Request\Accounting\ListEntries;
use Omnibill\Request\Request;

/** GET /ledger_entries filtered by date (gteq, lteq) and journal_id, the latest first (sort=-date), paged by Pennylane's cursor. */
final class ListEntriesAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof ListEntries;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof ListEntries);
        $journals = $this->journals();
        $filters = [];
        if (null !== $request->from) {
            $filters[] = ['date', 'gteq', $request->from->format('Y-m-d')];
        }
        if (null !== $request->to) {
            $filters[] = ['date', 'lteq', $request->to->format('Y-m-d')];
        }
        if (null !== $request->journal) {
            $filters[] = ['journal_id', 'eq', $journals[$request->journal] ?? throw new ProviderException(Api::PROVIDER, \sprintf('No journal with the code %s.', $request->journal), 'not_found')];
        }
        $page = $this->api->get('/ledger_entries', ['limit' => $request->limit, 'cursor' => $request->cursor, 'sort' => '-date'], $filters);
        $codes = array_flip($journals);
        $entries = array_map(static fn (array $item) => self::read(new JournalEntry('', new \DateTimeImmutable(self::string($item, 'date') ?? 'today'), ''), $item, $codes), Api::items($page));
        $request->setResult(new EntryList($entries, true === ($page['has_more'] ?? false) ? self::string($page, 'next_cursor') : null));
    }
}
