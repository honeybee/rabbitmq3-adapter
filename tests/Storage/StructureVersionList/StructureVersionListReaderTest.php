<?php

namespace Honeybee\Tests\RabbitMq3\Storage\StructureVersionList;

use Honeybee\Infrastructure\Config\ArrayConfig;
use Honeybee\Infrastructure\DataAccess\Storage\StorageReaderIterator;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\RabbitMq3\Storage\StructureVersionList\StructureVersionListReader;
use Honeybee\Tests\RabbitMq3\TestCase;
use Mockery;
use Psr\Log\NullLogger;
use Honeybee\Infrastructure\Migration\StructureVersionList;
use Honeybee\Infrastructure\Migration\StructureVersion;

class StructureVersionListReaderTest extends TestCase
{
    private $mockConnector;

    public function setUp()
    {
        $this->mockConnector = Mockery::mock(RabbitMqConnector::CLASS);
        $this->mockConnector->shouldReceive('isConnected')->never();
    }

    public function testGetIterator()
    {
        $versionReader = Mockery::mock(
            StructureVersionListReader::CLASS.'[readAll]',
            [$this->mockConnector, new ArrayConfig([]), new NullLogger()]
        );
        $versionReader->shouldReceive('readAll')->once()->andReturn(['something']);
        $iterator = $versionReader->getIterator();
        $this->assertInstanceOf(StorageReaderIterator::CLASS, $iterator);
        $this->assertTrue($iterator->valid());
    }

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadEmptyIdentifier()
    {
        $versionReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionReader->read('');
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadNonStringIdentifier()
    {
        $versionReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionReader->read(['test_id']);
    } //@codeCoverageIgnore

    /**
     * @expectedException Honeybee\Common\Error\RuntimeError
     */
    public function testReadMissingExchange()
    {
        $versionReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);

        $expectedList = new StructureVersionList('test_id');
        $this->assertEquals($expectedList, $versionReader->read('test_id'));
    } //@codeCoverageIgnore

    public function testReadEmpty()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'not_match', 'arguments' => []]
            ]);
        $versionReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $this->assertNull($versionReader->read('test_id'));
    }

    public function testRead()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'test_id', 'arguments' => ['version' => 2]],
                ['routing_key' => 'test_id', 'arguments' => ['version' => 1]]
            ]);
        $versionReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $expectedList = new StructureVersionList('test_id', [
            new StructureVersion(['version' => 1]),
            new StructureVersion(['version' => 2])
        ]);
        $this->assertEquals($expectedList, $versionReader->read('test_id'));
    }

    public function testReadAll()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'test_id', 'arguments' => ['version' => 2]],
                ['routing_key' => 'test_id', 'arguments' => ['version' => 1]]
            ]);
        $versionReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $expectedList = new StructureVersionList('test_id', [
            new StructureVersion(['version' => 1]),
            new StructureVersion(['version' => 2])
        ]);
        $this->assertEquals([$expectedList], $versionReader->readAll());
    }
}
