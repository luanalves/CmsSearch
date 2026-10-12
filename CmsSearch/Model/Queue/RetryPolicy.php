<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Queue;

use Thedevkitchen\CmsSearch\Model\Config;

/**
 * AMQP has no automatic retry (the framework consumer rejects with requeue=false), so the attempt counter of the
 * payload decides: with the default cap of 3 a message is tried 3 times (first try + 2 retries), then dead-lettered.
 */
class RetryPolicy
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param int $attempt zero-based attempt that just failed
     */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt + 1 < $this->config->getMaxAttempts();
    }
}
