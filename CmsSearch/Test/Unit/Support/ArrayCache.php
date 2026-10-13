<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Magento\Framework\App\CacheInterface;

/** In-memory Magento cache for unit tests; can be told to fail. */
class ArrayCache implements CacheInterface
{
    /** @var array<string, string> */
    public array $data = [];
    /** @var list<array{0: string, 1: array<string>, 2: ?int}> id, tags, lifetime of each save */
    public array $saves = [];

    public function __construct(public bool $failing = false)
    {
    }

    public function getFrontend()
    {
        throw new \LogicException('not used');
    }

    public function load($identifier)
    {
        if ($this->failing) {
            throw new \RuntimeException('cache down');
        }
        return $this->data[$identifier] ?? false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        if ($this->failing) {
            throw new \RuntimeException('cache down');
        }
        $this->data[$identifier] = (string) $data;
        $this->saves[] = [$identifier, $tags, $lifeTime];
        return true;
    }

    public function remove($identifier)
    {
        unset($this->data[$identifier]);
        return true;
    }

    public function clean($tags = [])
    {
        return true;
    }
}
