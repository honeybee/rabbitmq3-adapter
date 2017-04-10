<?php

namespace Honeybee\RabbitMq3\Job;

use Assert\Assertion;
use Closure;
use Honeybee\Infrastructure\Config\ConfigInterface;
use Honeybee\Infrastructure\Event\Bus\Channel\ChannelMap;
use Honeybee\Infrastructure\Event\Bus\EventBusInterface;
use Honeybee\Infrastructure\Event\FailedJobEvent;
use Honeybee\Infrastructure\Job\JobInterface;
use Honeybee\Infrastructure\Job\JobMap;
use Honeybee\Infrastructure\Job\JobService;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\ServiceLocatorInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

class RabbitMqJobService extends JobService
{
    private $connector;

    private $channel;

    public function __construct(
        RabbitMqConnector $connector,
        ServiceLocatorInterface $serviceLocator,
        EventBusInterface $eventBus,
        JobMap $jobMap,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        parent::__construct($serviceLocator, $eventBus, $jobMap, $config, $logger);
        $this->connector = $connector;
    }


    public function dispatch(JobInterface $job, $exchangeName)
    {
        $routingKey = $job->getSettings()->get('routing_key', '');

        Assertion::string($exchangeName);
        Assertion::string($routingKey);

        $messagePayload = json_encode($job->toArray());
        $message = new AMQPMessage($messagePayload, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);

        $this->getChannel()->basic_publish($message, $exchangeName, $routingKey);
    }

    public function consume($queueName, Closure $messageCallback)
    {
        Assertion::string($queueName);
        Assertion::notEmpty($queueName);

        $channel = $this->getChannel();

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queueName, false, true, false, false, false, $messageCallback);

        return $channel;
    }

    public function retry(JobInterface $job, $exchangeName, array $metadata = [])
    {
        $routingKey = $job->getSettings()->get('routing_key', '');

        Assertion::string($exchangeName);
        Assertion::string($routingKey);

        $jobState = $job->toArray();
        $jobState['metadata']['retries'] = isset($jobState['metadata']['retries'])
            ? ++$jobState['metadata']['retries'] : 1;

        /*
         * @todo better to retry by distributing the event back on the event bus and
         * calculating the expiration at that stage
         */
        $message = new AMQPMessage(
            json_encode($jobState),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'expiration' => $job->getStrategy()->getRetryInterval()
            ]
        );

        $this->getChannel()->basic_publish($message, $exchangeName, $routingKey);
    }

    public function fail(JobInterface $job, array $metadata = [])
    {
        $event = new FailedJobEvent([
            'failed_job_state' => $job->toArray(),
            'metadata' => $metadata
        ]);

        $this->eventBus->distribute(ChannelMap::CHANNEL_FAILED, $event);
    }

    protected function getChannel()
    {
        if (!$this->channel) {
            $this->channel = $this->connector->getConnection()->channel();
        }

        return $this->channel;
    }
}
