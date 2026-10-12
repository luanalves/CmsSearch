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
     *     (REST, grid inline edit, mass actions, Staging versions) or could not be loaded in the form (marker "0"):
     *     the link is then left untouched
     * @throws InputException malformed ids
     */
    public function read(DataObject $page): ?array
    {
        if (!$page->hasData(FormFields::MARKER) && !$page->hasData(FormFields::TYPE) && !$page->hasData(FormFields::TERM)) {
            return null;
        }
        if ($page->hasData(FormFields::MARKER) && (string) $page->getData(FormFields::MARKER) === '0') {
            return null;
        }
        return [
            'type_id' => $this->input->optionalId($page->getData(FormFields::TYPE)),
            'term_id' => $this->input->optionalId($page->getData(FormFields::TERM)),
        ];
    }
}
