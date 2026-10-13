<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Plugin\Staging;

use Magento\Staging\Model\StagingApplier;
use Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog;

/**
 * Opens the SentMessageLog for the duration of one StagingApplier run (cron staging_apply_version), closed even when
 * the run fails, so the post-processors can skip entities whose re-save already asked for their reindex. A nested
 * execute() (depth counter in the log) joins the outer run and does not close its log.
 */
class OpenSentMessageLog
{
    public function __construct(private readonly SentMessageLog $log)
    {
    }

    /**
     * @return mixed
     */
    public function aroundExecute(StagingApplier $subject, callable $proceed)
    {
        $this->log->enter();
        try {
            return $proceed();
        } finally {
            $this->log->leave();
        }
    }
}
