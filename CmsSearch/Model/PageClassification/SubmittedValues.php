<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\PageClassification;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;

/**
 * Reads the Search fieldset values carried by a Page being saved.
 */
class SubmittedValues
{
    public function __construct(private readonly StrictInput $input)
    {
    }

    /**
     * @return array{type_id: ?int, term_id: ?int}|null null when the fieldset was not part of the save
     *     (REST, grid inline edit, mass actions, Staging versions), could not be loaded in the form (marker "0")
     *     or carries any marker other than "1":
     *     the link is then left untouched
     * @throws InputException malformed ids
     */
    public function read(DataObject $page): ?array
    {
        // Only the exact marker "1" counts: Magento forms also post data-source keys without a rendered field, so
        // type/term alone (or marker "0", or anything else) must never clear or change a link.
        $marker = $page->getData(FormFields::MARKER);
        if (!is_scalar($marker) || (string) $marker !== '1') {
            return null;
        }
        return [
            'type_id' => $this->input->optionalId($page->getData(FormFields::TYPE)),
            'term_id' => $this->input->optionalId($page->getData(FormFields::TERM)),
        ];
    }
}
