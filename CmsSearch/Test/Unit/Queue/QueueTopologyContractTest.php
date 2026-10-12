<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Thedevkitchen\CmsSearch\Api\Data\ReindexMessageInterface;
use Thedevkitchen\CmsSearch\Model\Queue\ReindexHandler;

/**
 * Static contract of the queue declarations (D10, TS-29, TS-52). The broker side is checked against the real
 * RabbitMQ by integration/test_cms_search_queue.sh.
 */
class QueueTopologyContractTest extends TestCase
{
    private const TOPIC = 'thedevkitchen.cmssearch.reindex';
    private const DEAD_TOPIC = 'thedevkitchen.cmssearch.reindex.dead';
    private const EXCHANGE = 'thedevkitchen.cmssearch';
    private const DLX = 'thedevkitchen.cmssearch.dlx';
    private const QUEUE = 'thedevkitchen.cmssearch.reindex';
    private const DLQ = 'thedevkitchen.cmssearch.reindex.dlq';

    private function etc(string $file): \SimpleXMLElement
    {
        $path = dirname(__DIR__, 3) . '/etc/' . $file;
        $this->assertFileExists($path);
        $xml = simplexml_load_file($path);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml, "$file must be valid XML");
        return $xml;
    }

    public function testTheTopicsAreAsynchronousStrings(): void
    {
        $this->assertSame(self::TOPIC, ReindexMessageInterface::TOPIC);
        $topics = [];
        foreach ($this->etc('communication.xml')->xpath('//topic') as $topic) {
            $topics[(string) $topic['name']] = [
                (string) $topic['request'],
                (string) $topic['is_synchronous'],
                (string) $topic['response'],
                count($topic->xpath('handler')),
            ];
        }
        ksort($topics);
        // The dead topic is never published: Magento's topology DataMapper requires every non-wildcard binding topic
        // (here the DLQ binding) to be a declared communication topic.
        $this->assertSame([
            self::TOPIC => ['string', 'false', '', 0],
            self::DEAD_TOPIC => ['string', 'false', '', 0],
        ], $topics);
    }

    public function testOnlyTheReindexTopicIsPublishedOnTheDedicatedExchange(): void
    {
        $publishers = $this->etc('queue_publisher.xml')->xpath('//publisher');
        $this->assertCount(1, $publishers);
        $this->assertSame(self::TOPIC, (string) $publishers[0]['topic']);
        $connections = $publishers[0]->xpath('connection');
        $this->assertCount(1, $connections);
        $this->assertSame(['amqp', self::EXCHANGE], [(string) $connections[0]['name'], (string) $connections[0]['exchange']]);
    }

    public function testTheMainQueueDeadLettersIntoTheDlqThroughItsBindingArguments(): void
    {
        $exchanges = [];
        foreach ($this->etc('queue_topology.xml')->xpath('//exchange') as $exchange) {
            $bindings = [];
            foreach ($exchange->xpath('binding') as $binding) {
                $arguments = [];
                foreach ($binding->xpath('arguments/argument') as $argument) {
                    $arguments[(string) $argument['name']] = [
                        (string) $argument->attributes('xsi', true)->type,
                        (string) $argument,
                    ];
                }
                $bindings[] = [
                    'topic' => (string) $binding['topic'],
                    'destinationType' => (string) $binding['destinationType'],
                    'destination' => (string) $binding['destination'],
                    'arguments' => $arguments,
                ];
            }
            $exchanges[(string) $exchange['name']] = [
                'type' => (string) $exchange['type'],
                'connection' => (string) $exchange['connection'],
                'bindings' => $bindings,
            ];
        }
        ksort($exchanges);
        $this->assertSame([
            self::EXCHANGE => ['type' => 'topic', 'connection' => 'amqp', 'bindings' => [[
                'topic' => self::TOPIC,
                'destinationType' => 'queue',
                'destination' => self::QUEUE,
                // DataMapper copies BINDING arguments into queue_declare (topology.xsd has no <queue> element)
                'arguments' => [
                    'x-dead-letter-exchange' => ['string', self::DLX],
                    'x-dead-letter-routing-key' => ['string', self::DEAD_TOPIC],
                ],
            ]]],
            self::DLX => ['type' => 'topic', 'connection' => 'amqp', 'bindings' => [[
                'topic' => self::DEAD_TOPIC,
                'destinationType' => 'queue',
                'destination' => self::DLQ,
                'arguments' => [],
            ]]],
        ], $exchanges);
    }

    public function testOneConsumerReadsOnlyTheMainQueue(): void
    {
        $consumers = $this->etc('queue_consumer.xml')->xpath('//consumer');
        $this->assertCount(1, $consumers);
        $consumer = $consumers[0];
        $this->assertSame(
            [self::QUEUE, self::QUEUE, 'amqp', ReindexHandler::class . '::process', '1000'],
            [(string) $consumer['name'], (string) $consumer['queue'], (string) $consumer['connection'],
                ltrim((string) $consumer['handler'], '\\'), (string) $consumer['maxMessages']]
        );
        $method = new \ReflectionMethod(ReindexHandler::class, 'process');
        $this->assertTrue($method->isPublic());
        $this->assertSame(1, $method->getNumberOfRequiredParameters());
        $this->assertSame('string', (string) $method->getParameters()[0]->getType());
    }

    public function testNoOtherModuleDeclaresAnythingOnTheseTopicsOrQueues(): void
    {
        $root = dirname(__DIR__, 7);
        $files = [];
        foreach ([
            '/vendor/*/*/etc/queue*.xml',
            '/vendor/*/*/etc/communication.xml',
            '/app/code/*/*/etc/queue*.xml',
            '/app/code/*/*/etc/communication.xml',
        ] as $pattern) {
            $files = array_merge($files, glob($root . $pattern) ?: []);
        }
        $this->assertGreaterThan(100, count($files), 'the scan must see the vendor queue declarations');
        $own = (string) realpath(dirname(__DIR__, 3) . '/etc');
        $mentioning = [];
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), 'thedevkitchen.cmssearch')) {
                $mentioning[] = $file;
                $this->assertSame($own, realpath(dirname($file)), "only Thedevkitchen_CmsSearch may use thedevkitchen.cmssearch.*: $file");
            }
        }
        $this->assertCount(4, $mentioning, 'communication.xml + the three queue_*.xml files of this module');
    }

    public function testTheModuleLoadsAfterTheQueueAndStagingModules(): void
    {
        $names = array_map('strval', $this->etc('module.xml')->xpath('//sequence/module/@name'));
        foreach (['Magento_MessageQueue', 'Magento_Amqp', 'Magento_Staging', 'Magento_CmsStaging'] as $module) {
            $this->assertContains($module, $names);
        }
    }
}
