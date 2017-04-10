<?php

namespace Honeybee\Tests\RabbitMq3\Connector;

use Honeybee\Infrastructure\Config\ArrayConfig;
use Honeybee\Infrastructure\Config\ConfigInterface;
use Honeybee\Infrastructure\DataAccess\Connector\Status;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\Tests\RabbitMq3\TestCase;

class RabbitMqConnectorTest extends TestCase
{
    private function getConnector($name, ConfigInterface $config)
    {
        return new RabbitMqConnector($name, $config);
    }

    public function testGetNameWorks()
    {
        $connector = $this->getConnector('conn1', new ArrayConfig(['name' => 'foo']));
        $this->assertSame('conn1', $connector->getName());
    }

    /**
     * @expectedException Honeybee\Common\Error\ConfigError
     */
    public function testMissingUser()
    {
        $connector = $this->getConnector('conn1', new ArrayConfig(['name' => 'foo']));
        $this->assertSame('conn1', $connector->getConnection());
    } //@codeCoverageIgnore

    /**
     * @expectedException Honeybee\Common\Error\ConfigError
     */
    public function testMissingPassword()
    {
        $connector = $this->getConnector('conn1', new ArrayConfig(['name' => 'foo', 'user' => 'user1']));
        $this->assertSame('conn1', $connector->getConnection());
    } //@codeCoverageIgnore

    public function testGetConnectionWorks()
    {
        $connectorConfig = ['name' => 'foo', 'user' => 'user1', 'password' => 'pass1'];
        $connector = $this->getConnector('conn1', new ArrayConfig($connectorConfig));
        $connection = $connector->getConnection();
        $this->assertTrue($connector->isConnected(), 'Connector should be connected after getConnection() call');
        $this->assertTrue(is_object($connection), 'A getConnection() call should yield a client/connection object');
        $this->assertSame($connection, $connector->getConnection());
    }

    public function testDisconnectWorks()
    {
        $connectorConfig = ['name' => 'foo', 'user' => 'user1', 'password' => 'pass1'];
        $connector = $this->getConnector('conn1', new ArrayConfig($connectorConfig));
        $connector->getConnection();
        $this->assertTrue($connector->isConnected());
        $connector->disconnect();
        $this->assertFalse($connector->isConnected());
    }

    public function testGetConfigWorks()
    {
        $connector = $this->getConnector('conn1', new ArrayConfig(['foo' => 'bar']));
        $this->assertInstanceOf(ConfigInterface::CLASS, $connector->getConfig());
        $this->assertSame('bar', $connector->getConfig()->get('foo'));
    }

    public function testFakingStatusAsFailingSucceeds()
    {
        $connector = $this->getConnector('failing', new ArrayConfig(['fake_status' => Status::FAILING]));
        $status = $connector->getStatus();
        $this->assertTrue($status->isFailing());
        $this->assertSame('failing', $status->getConnectionName());
    }

    public function testFakingStatusAsWorkingSucceeds()
    {
        $connector = $this->getConnector('working', new ArrayConfig(['fake_status' => Status::WORKING]));
        $status = $connector->getStatus();
        $this->assertTrue($status->isWorking());
        $this->assertSame('working', $status->getConnectionName());
    }
}
