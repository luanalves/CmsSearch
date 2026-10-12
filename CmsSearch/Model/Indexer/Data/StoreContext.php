<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\Indexer\Data;

/**
 * What the index needs to know about one store view (scope, analyzer locale, URLs).
 */
final class StoreContext
{
    public function __construct(
        private readonly int $storeId,
        private readonly int $websiteId,
        private readonly string $code,
        private readonly string $locale,
        private readonly string $baseUrl,
        private readonly string $mediaUrl,
        private readonly string $homeIdentifier
    ) {
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** Link base URL of the store view, with a trailing slash. */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Media base URL of the store view, with a trailing slash. */
    public function getMediaUrl(): string
    {
        return $this->mediaUrl;
    }

    /** Identifier of the CMS home page of the store view (web/default/cms_home_page without "|id"). */
    public function getHomeIdentifier(): string
    {
        return $this->homeIdentifier;
    }
}
