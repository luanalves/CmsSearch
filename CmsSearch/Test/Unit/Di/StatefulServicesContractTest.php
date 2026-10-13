<?php

declare(strict_types=1);

namespace Thedevkitchen\CmsSearch\Test\Unit\Di;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared services keep state for the life of the process. ResetAfterRequestInterface is invoked ONLY by
 * Magento_ApplicationServer's Resetter: php-fpm (fresh process state per request) and the queue consumers do NOT call
 * it, so these resets are defensive and must not be relied on for consumer hygiene (consumers clear what they need
 * themselves). Every module class with mutable instance state either offers the reset (every property back to its
 * declared default) or is allowed to keep it, with the reason. A NEW stateful class fails this test until that decision is made.
 */
class StatefulServicesContractTest extends TestCase
{
    private const SCANNED = [
        'Block/Result', 'Model/Indexer', 'Model/Queue', 'Model/Reference', 'Model/Search', 'Model/Staging', 'Observer', 'Plugin', 'ViewModel',
    ];

    /** Stateful on purpose and NOT reset between requests. */
    private const KEPT = [
        \Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider::class => 'the OpenSearch client (connection), rebuilt after a dead node',
        \Thedevkitchen\CmsSearch\Model\Indexer\IndexManager::class => 'process caches of cluster state (alias existence, mapping warnings); a failed write forgets the alias',
        \Thedevkitchen\CmsSearch\Model\Queue\PendingMessages::class => 'messages of an open transaction; emptied by its COMMIT or ROLLBACK, never by a request boundary',
        \Thedevkitchen\CmsSearch\Model\Indexer\StoreListRefresher::class => 'consumer only: the fingerprint of the last store list it saw must survive between messages (that is its purpose)',
    ];

    private const EXPECTED = [
        \Thedevkitchen\CmsSearch\Model\Indexer\Engine\ClientProvider::class,
        \Thedevkitchen\CmsSearch\Model\Indexer\IndexManager::class,
        \Thedevkitchen\CmsSearch\Model\Indexer\StoreListRefresher::class,
        \Thedevkitchen\CmsSearch\Model\Queue\PendingMessages::class,
        \Thedevkitchen\CmsSearch\Model\Queue\SentMessageLog::class,
        \Thedevkitchen\CmsSearch\Model\Search\ClassificationTerms::class,
        \Thedevkitchen\CmsSearch\Model\Search\ContentTypeTabs::class,
        \Thedevkitchen\CmsSearch\Model\Search\ProductCount::class,
        \Thedevkitchen\CmsSearch\Model\Search\SearchState::class,
        \Thedevkitchen\CmsSearch\Observer\PublishRuleBannerReindex::class,
        \Thedevkitchen\CmsSearch\ViewModel\ClassificationFilter::class,
    ];

    /**
     * @return array<class-string, list<\ReflectionProperty>> classes with non-static, non-readonly declared properties
     */
    private function statefulClasses(): array
    {
        $module = dirname(__DIR__, 3);
        $found = [];
        foreach (self::SCANNED as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = (string) $file;
                if (!str_ends_with($path, '.php')) {
                    continue;
                }
                $class = 'Thedevkitchen\\CmsSearch\\' . str_replace(['/', '.php'], ['\\', ''], substr($path, strlen($module) + 1));
                if (!class_exists($class)) {
                    continue; // interfaces and traits
                }
                $mutable = array_values(array_filter(
                    (new \ReflectionClass($class))->getProperties(),
                    static fn (\ReflectionProperty $p): bool => !$p->isStatic() && !$p->isReadOnly() && $p->getDeclaringClass()->getName() === $class
                ));
                if ($mutable !== []) {
                    $found[$class] = $mutable;
                }
            }
        }
        ksort($found);
        return $found;
    }

    public function testEveryStatefulClassResetsItsStateOrIsAllowedToKeepIt(): void
    {
        $stateful = $this->statefulClasses();
        $this->assertSame(self::EXPECTED, array_keys($stateful), 'a new stateful class needs a decision: reset or KEPT');
        foreach (array_keys($stateful) as $class) {
            $resets = is_a($class, ResetAfterRequestInterface::class, true);
            $this->assertNotSame($resets, isset(self::KEPT[$class]), $class . ' must either reset its state or be in KEPT (not both)');
        }
    }

    public function testResetRestoresEveryPropertyToItsDeclaredDefault(): void
    {
        $checked = 0;
        foreach ($this->statefulClasses() as $class => $properties) {
            if (!is_a($class, ResetAfterRequestInterface::class, true)) {
                continue;
            }
            $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            foreach ($properties as $property) {
                $this->assertTrue($property->hasDefaultValue(), "$class::\${$property->getName()} needs a declared default");
                $property->setValue($instance, $this->sentinel($property));
            }
            $instance->_resetState();
            foreach ($properties as $property) {
                $this->assertEquals($property->getDefaultValue(), $property->getValue($instance), "$class::\${$property->getName()} is not reset");
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked);
    }

    private function sentinel(\ReflectionProperty $property): mixed
    {
        $default = $property->getDefaultValue();
        $type = $property->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        return match (true) {
            $name === 'array' => ['sentinel' => true],
            $name === 'bool' => !$default,
            $name === 'int' => (int) $default + 7,
            $name === 'string' => $default . 'sentinel',
            interface_exists($name) => $this->createMock($name),
            class_exists($name) => (new \ReflectionClass($name))->newInstanceWithoutConstructor(),
            default => 'sentinel',
        };
    }
}
