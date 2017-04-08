<?php

namespace Honeybee\Tests\RabbitMq3\Job;

use Honeybee\Infrastructure\Config\ArrayConfig;
use Honeybee\Infrastructure\Config\Settings;
use Honeybee\Infrastructure\Event\Bus\EventBusInterface;
use Honeybee\Infrastructure\Event\EventInterface;
use Honeybee\Infrastructure\Event\FailedJobEvent;
use Honeybee\Infrastructure\Job\JobInterface;
use Honeybee\Infrastructure\Job\JobMap;
use Honeybee\Infrastructure\Job\Strategy\Retry\RetryStrategyInterface;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\RabbitMq3\Job\RabbitMqJobService;
use Honeybee\ServiceLocatorInterface;
use Honeybee\Tests\RabbitMq3\TestCase;
use PhpAmqpLib\Channel\AbstractChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\NullLogger;
use Mockery;

class RabbitMqJobServiceTest extends TestCase
{
    protected $mockServiceLocator;

    protected $mockConnector;

    protected $mockConnection;

    protected $mockChannel;

    protected $mockJob;

    protected $mockEventBus;

    protected $mockClosure;

    public function setUp()
    {
        $this->mockServiceLocator = Mockery::mock(ServiceLocatorInterface::CLASS);
        $this->mockConnector = Mockery::mock(RabbitMqConnector::CLASS);
        $this->mockConnector->shouldReceive('isConnected')->never();
        $this->mockConnection = Mockery::mock(AbstractConnection::CLASS);
        $this->mockChannel = Mockery::mock(AbstractChannel::CLASS);
        $this->mockJob = Mockery::mock(JobInterface::CLASS);
        $this->mockEventBus = Mockery::mock(EventBusInterface::CLASS);
        $this->mockClosure = function () {
        }; //@codeCoverageIgnore
    }

    public function testDispatch()
    {
        $expected = $this->getAMQPMessage([ 'data' => 'state' ]);
        $this->mockChannel->shouldReceive('basic_publish')
            ->once()
            ->with(
                Mockery::on(
                    function (AMQPMessage $message) use ($expected) {
                        $this->assertEquals($expected, $message);
                        return true;
                    }
                ),
                'exchange',
                'route'
            );
        $this->mockConnection->shouldReceive('channel')->once()->andReturn($this->mockChannel);
        $this->mockConnector->shouldReceive('getConnection')->once()->andReturn($this->mockConnection);

        $this->mockJob->shouldReceive('toArray')->andReturn([ 'data' => 'state' ]);
        $this->mockJob->shouldReceive('getSettings')->andReturn(new Settings([ 'routing_key' => 'route' ]));
        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            $jobMap = new JobMap([ 'job' => [ 'state' ] ]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertEquals($jobMap, $jobService->getJobMap());
        $this->assertEquals(new Settings([ 'state' ]), $jobService->getJob('job'));
        $this->assertNull($jobService->dispatch($this->mockJob, 'exchange'));
    }

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testDispatchInvalidExchange()
    {
        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->mockJob->shouldReceive('getSettings')->andReturn(new Settings([ 'routing_key' => 'route' ]));
        $jobService->dispatch($this->mockJob, []);
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testDispatchInvalidRoutingKey()
    {
        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->mockJob->shouldReceive('getSettings')->andReturn(new Settings([ 'routing_key' => 7 ]));
        $jobService->dispatch($this->mockJob, 'exchange');
    } //@codeCoverageIgnore

    public function testConsume()
    {
        $this->mockChannel->shouldReceive('basic_qos')->once()->with(null, 1, null);
        $this->mockChannel->shouldReceive('basic_consume')
            ->once()
            ->with('queue', false, true, false, false, false, $this->mockClosure);
        $this->mockConnection->shouldReceive('channel')->once()->andReturn($this->mockChannel);
        $this->mockConnector->shouldReceive('getConnection')->once()->andReturn($this->mockConnection);

        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertEquals($this->mockChannel, $jobService->consume('queue', $this->mockClosure));
    }

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testConsumeEmptyQueue()
    {
        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertEquals($this->mockChannel, $jobService->consume('', $this->mockClosure));
    } //@codeCoverageIgnore

    /**
     * @expectedException Assert\InvalidArgumentException
     */
    public function testConsumeInvalidQueue()
    {
        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertEquals($this->mockChannel, $jobService->consume(9, $this->mockClosure));
    } //@codeCoverageIgnore

    public function testRetry()
    {
        $expected = $this->getAMQPMessage(
            [ 'job' => 'state', 'metadata' => [ 'retries' => 1 ] ],
            [ 'delivery_mode' => 2, 'expiration' => 123 ]
        );
        $this->mockChannel
            ->shouldReceive('basic_publish')
            ->once()
            ->with(
                Mockery::on(
                    function (AMQPMessage $message) use ($expected) {
                        $this->assertEquals($expected, $message);
                        return true;
                    }
                ),
                'exchange',
                'route'
            );
        $this->mockConnection->shouldReceive('channel')->once()->andReturn($this->mockChannel);
        $this->mockConnector->shouldReceive('getConnection')->once()->andReturn($this->mockConnection);
        $this->mockStrategy = Mockery::mock(RetryStrategyInterface::CLASS);
        $this->mockStrategy->shouldReceive('getRetryInterval')->once()->andReturn(123);
        $this->mockJob->shouldReceive('getSettings')->once()->andReturn(new Settings([ 'routing_key' => 'route' ]));
        $this->mockJob->shouldReceive('toArray')->once()->andReturn([ 'job' => 'state' ]);
        $this->mockJob->shouldReceive('getStrategy')->once()->andReturn($this->mockStrategy);

        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertNull($jobService->retry($this->mockJob, 'exchange', []));
    }

    public function testFail()
    {
        $expected = new FailedJobEvent([
            'failed_job_state' => [ 'job' => 'state' ],
            'metadata' => [ 'message' => 'fail' ]
        ]);
        $this->mockJob->shouldReceive('toArray')->once()->andReturn([ 'job' => 'state' ]);
        $this->mockEventBus
            ->shouldReceive('distribute')
            ->once()
            ->with(
                'honeybee.events.failed',
                Mockery::on(
                    function (EventInterface $event) use ($expected) {
                        $expected = array_merge(
                            $expected->toArray(),
                            [ 'uuid' => $event->getUuid(), 'iso_date' => $event->getIsoDate() ]
                        );
                        $this->assertEquals($expected, $event->toArray());
                        return true;
                    }
                )
            );

        $jobService = new RabbitMqJobService(
            $this->mockConnector,
            $this->mockServiceLocator,
            $this->mockEventBus,
            new JobMap([]),
            new ArrayConfig([]),
            new NullLogger
        );

        $this->assertNull($jobService->fail($this->mockJob, [ 'message' => 'fail' ]));
    }

    private function getAMQPMessage(array $payload, array $options = [])
    {
        return new AMQPMessage(
            json_encode($payload),
            array_merge([ 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT ], $options)
        );
    }
}
