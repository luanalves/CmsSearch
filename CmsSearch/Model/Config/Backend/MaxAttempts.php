<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Thedevkitchen\CmsSearch\Model\Config;

/**
 * Server-side validation of "Maximum Attempts" (the form validation alone can be bypassed).
 */
class MaxAttempts extends Value
{
    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        if (preg_match('/^[0-9]{1,2}$/', $value) !== 1
            || (int) $value < Config::MIN_MAX_ATTEMPTS
            || (int) $value > Config::MAX_MAX_ATTEMPTS
        ) {
            throw new LocalizedException(__(
                'The maximum number of attempts must be a whole number between %1 and %2.',
                Config::MIN_MAX_ATTEMPTS,
                Config::MAX_MAX_ATTEMPTS
            ));
        }
        $this->setValue((string) (int) $value);
        return parent::beforeSave();
    }
}
