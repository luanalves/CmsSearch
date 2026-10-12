<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Model\PageClassification;

/**
 * Names of the "Search" fieldset of the CMS Page form; they are also the POST keys read on save.
 */
final class FormFields
{
    public const FIELDSET = 'thedevkitchen_cmssearch';
    public const TYPE = 'thedevkitchen_cmssearch_type_id';
    public const TERM = 'thedevkitchen_cmssearch_term_id';
    /** Hidden field (value "1"): tells the save observer that the fieldset was part of the submitted form. */
    public const MARKER = 'thedevkitchen_cmssearch_submitted';
}
