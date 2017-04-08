<?php

namespace Honeybee\RabbitMq3\Job;

use Assert\Assertion;
use Closure;
use Honeybee\Common\Error\RuntimeError;
use Honeybee\Infrastructure\Config\ConfigInterface;
use Honeybee\Infrastructure\Event\Bus\Channel\ChannelMap;
use Honeybee\Infrastructure\Event\Bus\EventBusInterface;
use Honeybee\Infrastructure\Event\FailedJobEvent;
use Honeybee\Infrastructure\Job\JobInterface;
use Honeybee\Infrastructure\Job\JobMap;
use Honeybee\Infrastructure\Job\JobServiceInterface;
use Honeybee\RabbitMq3\Connector\RabbitMqConnector;
use Honeybee\ServiceLocatorInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

class RabbitMqJobService implements JobServiceInterface
{
    protected $connector;

    protected $serviceLocator;

    protected $eventBus;

    protected $jobMap;

    protected $config;

    protected $logger;

    protected $channel;

    public function __construct(
        RabbitMqConnector $connector,
        ServiceLocatorInterface $serviceLocator,
        EventBusInterface $eventBus,
        JobMap $jobMap,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->serviceLocator = $serviceLocator;
        $this->eventBus = $eventBus;
        $this->jobMap = $jobMap;
        $this->connector = $connector;
        $this->logger = $logger;
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

    /**
     * @todo Job building and JobMap should be provisioned and injected where required, and
     * so the following public methods are not specified on the interface.
     */
    public function createJob(array $jobState)
    {
        if (!isset($jobState['metadata']['job_name']) || empty($jobState['metadata']['job_name'])) {
            throw new RuntimeError('Unable to get job name from metadata.');
        }

        $jobName = $jobState['metadata']['job_name'];

        $jobConfig = $this->getJob($jobName);
        $strategyConfig = $jobConfig['strategy'];
        $serviceLocator = $this->serviceLocator;

        $strategyCallback = function (JobInterface $job) use ($serviceLocator, $strategyConfig) {
            $strategyImplementor = $strategyConfig['implementor'];

            $retryStrategy = $serviceLocator->make(
                $strategyConfig['retry']['implementor'],
                [':job' => $job, ':settings' => $strategyConfig['retry']['settings']]
            );

            $failureStrategy = $serviceLocator->make(
                $strategyConfig['failure']['implementor'],
                [':job' => $job, ':settings' => $strategyConfig['failure']['settings']]
            );

            return new $strategyImplementor($retryStrategy, $failureStrategy);
        };

        return $this->serviceLocator->make(
            $jobConfig['class'],
            [
                // job class cannot be overridden by state
                ':state' => ['@type' => $jobConfig['class']] + $jobState,
                ':strategy_callback' => $strategyCallback,
                ':settings' => $jobConfig['settings']
            ]
        );
    }

    public function getJobMap()
    {
        return $this->jobMap;
    }

    public function getJob($jobName)
    {
        $jobConfig = $this->jobMap->get($jobName);

        if (!$jobConfig) {
            throw new RuntimeError(sprintf('Configuration for job "%s" was not found.', $jobName));
        }

        return $jobConfig;
    }

    protected function getChannel()
    {
        if (!$this->channel) {
            $this->channel = $this->connector->getConnection()->channel();
        }

        return $this->channel;
    }
}
