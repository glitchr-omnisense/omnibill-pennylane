<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Request\Accounting\FetchEntry;
use Omnibill\Request\Request;

/** GET /ledger_entries/{id}, and the journals' codes: the entry as Pennylane has it, its lines on their accounts' numbers. */
final class FetchEntryAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchEntry;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchEntry);
        $answer = $this->api->get('/ledger_entries/'.rawurlencode($request->entry->reference()));
        $request->setResult(self::read($request->entry, $answer, array_flip($this->journals())));
    }
}
