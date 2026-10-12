<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Model\PageClassification;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Model\Input\StrictInput;
use Thedevkitchen\CmsSearch\Model\PageClassification\FormFields;
use Thedevkitchen\CmsSearch\Model\PageClassification\SubmittedValues;

class SubmittedValuesTest extends TestCase
{
    private function read(array $data): ?array
    {
        return (new SubmittedValues(new StrictInput()))->read(new DataObject($data));
    }

    public function testASaveWithoutTheFieldsetChangesNothing(): void
    {
        $this->assertNull($this->read(['title' => 'Home', 'store_id' => [1]]));
    }

    public function testTheMarkerAloneMeansBothWereCleared(): void
    {
        $this->assertSame(['type_id' => null, 'term_id' => null], $this->read([FormFields::MARKER => '1']));
    }

    public function testPostedIdsAreParsed(): void
    {
        $this->assertSame(
            ['type_id' => 3, 'term_id' => 10],
            $this->read([FormFields::MARKER => '1', FormFields::TYPE => '3', FormFields::TERM => '10'])
        );
    }

    public function testFieldsWithoutTheMarkerStillCount(): void
    {
        $this->assertSame(['type_id' => 3, 'term_id' => null], $this->read([FormFields::TYPE => '3', FormFields::TERM => '']));
    }

    public function testAFieldsetThatCouldNotBeLoadedIsNotASubmission(): void
    {
        // PageFormDataPlugin posts marker "0" when the current link could not be read: never clear an unknown link.
        $this->assertNull($this->read([FormFields::MARKER => '0', FormFields::TYPE => '', FormFields::TERM => '']));
    }

    public function testMalformedIdsAreRejected(): void
    {
        $this->expectException(InputException::class);
        $this->read([FormFields::MARKER => '1', FormFields::TYPE => '3 OR 1=1', FormFields::TERM => '10']);
    }
}
