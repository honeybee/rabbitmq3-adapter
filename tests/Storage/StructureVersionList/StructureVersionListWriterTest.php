<?php

namespace Honeybee\Tests\RabbitMq3\Storage\StructureVersionList;

use Honeybee\Infrastructure\Config\ArrayConfig;
use Honeybee\Infrastructure\Migration\StructureVersion;
use Honeybee\Infrastructure\Migration\StructureVersionList;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\RabbitMq3\Storage\StructureVersionList\StructureVersionListWriter;
use Honeybee\Tests\RabbitMq3\TestCase;
use Mockery;
use Psr\Log\NullLogger;

class StructureVersionListWriterTest extends TestCase
{
    private $mockConnector;

    public function setUp()
    {
        $this->mockConnector = Mockery::mock(RabbitMqConnector::CLASS);
        $this->mockConnector->shouldReceive('isConnected')->never();
    }

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testWriteInvalidVersionList()
    {
        $versionListWriter = new StructureVersionListWriter($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionListWriter->write('invalid');
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testWriteMissingExchange()
    {
        $versionListWriter = new StructureVersionListWriter($this->mockConnector, new ArrayConfig([]), new NullLogger);
        $versionList = new StructureVersionList('test_id');
        $versionListWriter->write($versionList);
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testWriteInvalidExchange()
    {
        $versionListWriter = new StructureVersionListWriter(
            $this->mockConnector,
            new ArrayConfig(['exchange' => '']),
            new NullLogger
        );

        $versionList = new StructureVersionList('test_id');
        $versionListWriter->write($versionList);
    } //@codeCoverageIgnore

    public function testWrite()
    {
        $mockChannel = Mockery::mock(\AMQPChannel::CLASS);
        $mockChannel->shouldReceive('exchange_unbind', 'exchange_bind')->once()->with(
            'test_exchange',
            'test_exchange',
            'test_id',
            false,
            [
                '@type' => ['S', StructureVersion::CLASS],
                'target_name' => ['S', 'test_target'],
                'version' => ['S', 123],
                'created_date' => ['S', date(DATE_ISO8601)]
            ]
        )->andReturnNull();
        $mockConnection = Mockery::mock(\AMQPConnection::CLASS);
        $mockConnection->shouldReceive('channel')->twice()->withNoArgs()->andReturn($mockChannel);
        $this->mockConnector->shouldReceive('getConnection')->twice()->withNoArgs()->andReturn($mockConnection);
        $versionListWriter = new StructureVersionListWriter(
            $this->mockConnector,
            new ArrayConfig(['exchange' => 'test_exchange']),
            new NullLogger
        );

        $mockVersionList = new StructureVersionList('test_id', [new StructureVersion([
            'target_name' => 'test_target',
            'version' => 123
        ])]);
        $this->assertNull($versionListWriter->write($mockVersionList));
    }
}
