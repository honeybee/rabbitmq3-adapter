<?php

namespace Honeybee\RabbitMq3\Storage;

use Honeybee\Infrastructure\DataAccess\Storage\Storage;

abstract class RabbitMqStorage extends Storage
{
    protected function getExchangeBindings()
    {
        $config = $this->getConfig();
        $vhost = $config->get('vhost', '%2f');
        $exchange = $config->get('exchange');

        $endpoint = "/api/exchanges/$vhost/$exchange/bindings/source";
        return (array)$this->connector->getFromAdminApi($endpoint);
    }
}
