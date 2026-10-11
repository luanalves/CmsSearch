<?php

declare(strict_types=1);

namespace Thedevkitchen\RestrictionReason\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Thedevkitchen\RestrictionReason\Model\Config\Paths;

class Reason extends Value
{
    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = $this->sanitize((string) $this->getValue());
        $max = $this->getMaxLength();

        if (mb_strlen($value) > $max) {
            throw new LocalizedException(
                __('Restriction reason cannot be longer than %1 characters.', $max)
            );
        }

        $this->setValue($value);

        return parent::beforeSave();
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value) ?? $value;

        return trim(strip_tags($value));
    }

    private function getMaxLength(): int
    {
        $configured = $this->_config->getValue(Paths::MAX_LENGTH);

        return is_numeric($configured) && (int) $configured >= 1
            ? (int) $configured
            : Paths::DEFAULT_MAX_LENGTH;
    }
}
