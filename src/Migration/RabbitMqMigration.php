<?php

namespace Honeybee\RabbitMq3\Migration;

use Assert\Assertion;
use Honeybee\Infrastructure\Migration\Migration;
use Honeybee\Infrastructure\Migration\MigrationTargetInterface;

abstract class RabbitMqMigration extends Migration
{
    const WAIT_SUFFIX = '.waiting';

    const UNROUTED_SUFFIX = '.unrouted';

    const REPUB_SUFFIX = '.repub';

    const QUEUE_SUFFIX = '.q';

    const REPUB_INTERVAL = 30000; //30 seconds

    protected function createQueue(
        MigrationTargetInterface $migrationTarget,
        $exchangeName,
        $queueName,
        $routingKey
    ) {
        Assertion::string($exchangeName);
        Assertion::string($routingKey);
        Assertion::string($queueName);

        $channel = $this->getConnection($migrationTarget)->channel();
        $channel->queue_declare($queueName, false, true, false, false);
        $channel->queue_bind($queueName, $exchangeName, $routingKey);
    }

    protected function createVersionList(MigrationTargetInterface $migrationTarget, $exchangeName)
    {
        Assertion::string($exchangeName);

        $channel = $this->getConnection($migrationTarget)->channel();
        $channel->exchange_declare($exchangeName, 'topic', false, true, false, true);
    }

    protected function createExchangePipeline(MigrationTargetInterface $migrationTarget, $exchangeName)
    {
        Assertion::string($exchangeName);

        $waitExchangeName = $exchangeName . self::WAIT_SUFFIX;
        $waitQueueName = $waitExchangeName . self::QUEUE_SUFFIX;
        $unroutedExchangeName = $exchangeName . self::UNROUTED_SUFFIX;
        $unroutedQueueName = $unroutedExchangeName . self::QUEUE_SUFFIX;
        $repubExchangeName = $exchangeName . self::REPUB_SUFFIX;
        $repubQueueName = $repubExchangeName . self::QUEUE_SUFFIX;

        $channel = $this->getConnection($migrationTarget)->channel();

        // Setup the default exchange and queue pipelines
        $channel->exchange_declare($unroutedExchangeName, 'fanout', false, true, false, true); //internal
        $channel->exchange_declare($repubExchangeName, 'fanout', false, true, false, true); //internal
        $channel->exchange_declare($waitExchangeName, 'fanout', false, true, false);
        $channel->exchange_declare($exchangeName, 'direct', false, true, false, false, false, [
            'alternate-exchange' => ['S', $unroutedExchangeName]
        ]);
        $channel->queue_declare($waitQueueName, false, true, false, false, false, [
            'x-dead-letter-exchange' => ['S', $exchangeName]
        ]);
        $channel->queue_bind($waitQueueName, $waitExchangeName);
        $channel->queue_declare($unroutedQueueName, false, true, false, false, false, [
            'x-dead-letter-exchange' => ['S', $repubExchangeName],
            'x-message-ttl' => ['I', self::REPUB_INTERVAL]
        ]);
        $channel->queue_bind($unroutedQueueName, $unroutedExchangeName);
        $channel->queue_declare($repubQueueName, false, true, false, false);
        $channel->queue_bind($repubQueueName, $repubExchangeName);

        $this->createShovel($migrationTarget, $repubExchangeName, $exchangeName, $repubQueueName);
    }

    protected function createShovel(
        MigrationTargetInterface $migrationTarget,
        $srcExchangeName,
        $destExchangeName,
        $srcQueue
    ) {
        $connector = $migrationTarget->getTargetConnector();

        $endpoint = sprintf(
            '/api/parameters/shovel/%s/%s.shovel',
            $connector->getConfig()->get('vhost', '%2f'),
            $srcExchangeName
        );

        $body = [
            'value' => [
                'src-uri' => 'amqp://',
                'src-queue' => $srcQueue,
                'dest-uri' => 'amqp://',
                'dest-exchange' => $destExchangeName,
                'add-forward-headers' => false,
                'ack-mode' => 'on-confirm',
                'delete-after' => 'never'
            ]
        ];

        $connector->putToAdminApi($endpoint, $body);
    }
}
