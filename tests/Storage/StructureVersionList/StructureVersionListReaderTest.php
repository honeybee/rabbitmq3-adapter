<?php

namespace Honeybee\Tests\RabbitMq3\Storage\StructureVersionList;

use Honeybee\Infrastructure\Config\ArrayConfig;
use Honeybee\Infrastructure\DataAccess\Storage\StorageReaderIterator;
use Honeybee\Infrastructure\Migration\StructureVersion;
use Honeybee\Infrastructure\Migration\StructureVersionList;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\RabbitMq3\Storage\StructureVersionList\StructureVersionListReader;
use Honeybee\Tests\RabbitMq3\TestCase;
use Mockery;
use Psr\Log\NullLogger;

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
        $versionListReader = Mockery::mock(
            StructureVersionListReader::CLASS.'[readAll]',
            [$this->mockConnector, new ArrayConfig([]), new NullLogger()]
        );
        $versionListReader->shouldReceive('readAll')->once()->andReturn(['something']);
        $iterator = $versionListReader->getIterator();
        $this->assertInstanceOf(StorageReaderIterator::CLASS, $iterator);
        $this->assertTrue($iterator->valid());
    }

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadEmptyIdentifier()
    {
        $versionListReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionListReader->read('');
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadNonStringIdentifier()
    {
        $versionListReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionListReader->read(['test_id']);
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadMissingExchange()
    {
        $versionListReader = new StructureVersionListReader($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionListReader->read('test_id');
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testReadInvalidExchange()
    {
        $versionListReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => '']),
            new NullLogger
        );

        $versionListReader->read('test_id');
    } //@codeCoverageIgnore

    public function testReadEmpty()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'not_match', 'arguments' => []]
            ]);
        $versionListReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $this->assertNull($versionListReader->read('test_id'));
    }

    public function testRead()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'test_id', 'arguments' => ['version' => 2]],
                ['routing_key' => 'test_id', 'arguments' => ['version' => 1]]
            ]);
        $versionListReader = new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $expectedList = new StructureVersionList('test_id', [
            new StructureVersion(['version' => 1]),
            new StructureVersion(['version' => 2])
        ]);
        $this->assertEquals($expectedList, $versionListReader->read('test_id'));
    }

    public function testReadAll()
    {
        $this->mockConnector->shouldReceive('getFromAdminApi')->once()
            ->with('/api/exchanges/%2f/test_exchange/bindings/source')->andReturn([
                ['routing_key' => 'test_id', 'arguments' => ['version' => 2]],
                ['routing_key' => 'test_id', 'arguments' => ['version' => 1]]
            ]);
        $versionListReader= new StructureVersionListReader(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $expectedList = new StructureVersionList('test_id', [
            new StructureVersion(['version' => 1]),
            new StructureVersion(['version' => 2])
        ]);
        $this->assertEquals([$expectedList], $versionListReader->readAll());
    }
}
