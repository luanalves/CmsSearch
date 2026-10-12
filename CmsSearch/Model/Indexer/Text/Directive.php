<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Text;

/**
 * One {{name param="value" ...}} directive found in raw CMS content (Plan 6 resolves block/widget references).
 */
final class Directive
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        private readonly string $name,
        private readonly array $params,
        private readonly string $raw
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getParam(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }
}
