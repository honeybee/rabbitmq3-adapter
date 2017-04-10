<?php

namespace Honeybee\RabbitMq3\Storage;

use Assert\Assertion;
use Honeybee\Common\Error\RuntimeError;
use Honeybee\Infrastructure\DataAccess\Storage\Storage;

abstract class RabbitMqStorage extends Storage
{
    protected function getExchangeBindings()
    {
        $vhost = $this->getVHost();
        $exchange = $this->getExchange();

        $endpoint = "/api/exchanges/$vhost/$exchange/bindings/source";
        return (array)$this->connector->getFromAdminApi($endpoint);
    }

    protected function getExchange()
    {
        $exchange = $this->config->get('exchange');
        Assertion::string($exchange);
        Assertion::notBlank($exchange);
        return $exchange;
    }

    protected function getVHost()
    {
        $vhost = $this->config->get('vhost', '%2f');
        Assertion::string($vhost);
        Assertion::notBlank($vhost);
        return $vhost;
    }
}
