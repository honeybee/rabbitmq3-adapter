<?php

namespace Honeybee\RabbitMq3\Storage\StructureVersionList;

use Assert\Assertion;
use Honeybee\Infrastructure\Config\Settings;
use Honeybee\Infrastructure\Config\SettingsInterface;
use Honeybee\Infrastructure\DataAccess\Storage\StorageWriterInterface;
use Honeybee\Infrastructure\Migration\StructureVersion;
use Honeybee\Infrastructure\Migration\StructureVersionList;
use Honeybee\RabbitMq3\Storage\RabbitMqStorage;

class StructureVersionListWriter extends RabbitMqStorage implements StorageWriterInterface
{
    public function write($structureVersionList, SettingsInterface $settings = null)
    {
        Assertion::isInstanceOf($structureVersionList, StructureVersionList::CLASS);

        $exchange = $this->getConfig()->get('exchange');
        $channel = $this->connector->getConnection()->channel();

        // delete existing bindings by identifier & arguments
        foreach ($structureVersionList as $structureVersion) {
            $this->delete(
                $structureVersionList->getIdentifier(),
                new Settings(['arguments' => $this->buildArguments($structureVersion)])
            );
        }

        // recreate all the bindings
        foreach ($structureVersionList as $structureVersion) {
            $channel->exchange_bind(
                $exchange,
                $exchange,
                $structureVersionList->getIdentifier(),
                false,
                $this->buildArguments($structureVersion)
            );
        }
    }

    public function delete($identifier, SettingsInterface $settings = null)
    {
        $arguments = $settings->get('arguments');

        Assertion::isInstanceOf($arguments, SettingsInterface::CLASS);

        $exchange = $this->getConfig()->get('exchange');
        $channel = $this->connector->getConnection()->channel();
        $channel->exchange_unbind($exchange, $exchange, $identifier, false, $arguments->toArray());
    }

    protected function buildArguments(StructureVersion $structureVersion)
    {
        return [
            '@type' => ['S', get_class($structureVersion)],
            'target_name' => ['S', $structureVersion->getTargetName()],
            'version' => ['S', $structureVersion->getVersion()],
            'created_date' => ['S', $structureVersion->getCreatedDate()]
        ];
    }
}
