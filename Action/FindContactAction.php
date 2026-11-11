<?php

namespace Omnibill\Pennylane\Action;

use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\ContactKind;
use Omnibill\Pennylane\Api;
use Omnibill\Request\Accounting\FindContact;
use Omnibill\Request\Request;

/**
 * GET /suppliers or /customers filtered on external_reference (eq), the
 * key saveContact() sent: Pennylane's Company API v2 reference (read
 * 2026-11-11) lists external_reference among both lists' filters, and
 * calls it the customer's unique external reference. The one whose
 * external_reference is the key, checked on what comes back; null when
 * none. A contact without a key cannot be found: Pennylane gives one of its
 * own to a contact created without.
 */
final class FindContactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FindContact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FindContact);
        $contact = $request->contact;
        $key = $contact->key ?? throw new InvalidConfigException(\sprintf('The contact "%s" has no key to be found by.', $contact->name));
        $page = $this->api->get(ContactKind::SUPPLIER === $contact->kind ? '/suppliers' : '/customers', ['limit' => 100], [['external_reference', 'eq', $key]]);
        foreach (Api::items($page) as $item) {
            if ($key === self::string($item, 'external_reference')) {
                $request->setResult(self::contact($contact->kind, $item, $contact));

                return;
            }
        }
        $request->setResult(null);
    }
}
