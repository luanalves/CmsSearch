<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Support;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps every record (level, message, context) for assertions. */
class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
