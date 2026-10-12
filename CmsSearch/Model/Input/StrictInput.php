<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Input;

use Magento\Framework\Exception\InputException;

/**
 * Strict parsing of admin input: anything that is not exactly the expected shape is rejected.
 */
class StrictInput
{
    public const MAX_ID = 4294967295;

    /**
     * '' or null => null; a positive integer (int, or digit string without leading zero) up to MAX_ID => int.
     *
     * @throws InputException
     */
    public function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1 || (int) $value > self::MAX_ID) {
            throw $this->invalid();
        }
        return (int) $value;
    }

    /**
     * '' or null => 0; a non-negative integer => int (the range is checked by the repository).
     *
     * @throws InputException
     */
    public function sortOrder(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,10}$/', $value) === 1) {
            return (int) $value;
        }
        throw $this->invalid();
    }

    /**
     * Only 0 and 1 (int or string) are accepted.
     *
     * @throws InputException
     */
    public function flag(mixed $value): bool
    {
        if (in_array($value, [0, 1, '0', '1'], true)) {
            return (bool) (int) $value;
        }
        throw $this->invalid();
    }

    private function invalid(): InputException
    {
        return new InputException(__('Please correct the data sent.'));
    }
}
