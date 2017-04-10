<?php

namespace Honeybee\RabbitMq3\Storage;

use Honeybee\Common\Error\RuntimeError;
use Honeybee\Infrastructure\DataAccess\Storage\Storage;

abstract class RabbitMqStorage extends Storage
{
    protected function getExchangeBindings()
    {
        $config = $this->getConfig();
        $vhost = $config->get('vhost', '%2f');
        $exchange = $config->get('exchange');

        if (!$exchange || !is_string($exchange)) {
            throw new RuntimeError('Invalid exchange specified to read RabbitMq bindings from.');
        }

        $endpoint = "/api/exchanges/$vhost/$exchange/bindings/source";
        return (array)$this->connector->getFromAdminApi($endpoint);
    }
}
