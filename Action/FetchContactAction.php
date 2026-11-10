<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Model\ContactKind;
use Omnibill\Request\Accounting\FetchContact;
use Omnibill\Request\Request;

/** GET /customers/{id} or /suppliers/{id}. */
final class FetchContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FetchContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchContact);
        $contact = $request->contact;
        $answer = $this->api->get((ContactKind::SUPPLIER === $contact->kind ? '/suppliers/' : '/customers/').rawurlencode($contact->reference()));
        $request->setResult(self::contact($contact->kind, $answer, $contact));
    }
}
