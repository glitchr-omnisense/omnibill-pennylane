<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Model\ContactKind;
use Omnibill\Model\ContactList;
use Omnibill\Pennylane\Api;
use Omnibill\Request\Accounting\ListContacts;
use Omnibill\Request\Request;

/** GET /customers or /suppliers, the name starting with the search (start_with), paged by Pennylane's cursor. */
final class ListContactsAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof ListContacts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof ListContacts);
        $page = $this->api->get(ContactKind::SUPPLIER === $request->kind ? '/suppliers' : '/customers', ['limit' => $request->limit, 'cursor' => $request->cursor], null !== $request->search && '' !== $request->search ? [['name', 'start_with', $request->search]] : []);
        $request->setResult(new ContactList(
            array_map(static fn (array $item) => self::contact($request->kind, $item), Api::items($page)),
            true === ($page['has_more'] ?? false) ? self::string($page, 'next_cursor') : null,
        ));
    }
}
