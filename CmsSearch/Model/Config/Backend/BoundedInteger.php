<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Server-side validation of a whole-number setting (the form validation alone can be bypassed).
 */
abstract class BoundedInteger extends Value
{
    abstract protected function minimum(): int;

    abstract protected function maximum(): int;

    /** Translated field name used in the error message. */
    abstract protected function fieldLabel(): string;

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if (preg_match('/^[0-9]{1,6}$/', $value) !== 1
            || (int) $value < $this->minimum()
            || (int) $value > $this->maximum()
        ) {
            throw new LocalizedException(__(
                '%1 must be a whole number between %2 and %3.',
                $this->fieldLabel(),
                $this->minimum(),
                $this->maximum()
            ));
        }
        $this->setValue((string) (int) $value);
        return parent::beforeSave();
    }
}
