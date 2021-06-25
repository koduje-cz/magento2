<?php

declare(strict_types=1);

namespace Packetery\Checkout\Model\Carrier\Imp\PacketeryPacketaDynamic;

use Packetery\Checkout\Model\Carrier\Config\AbstractConfig;

/**
 * PacketaDynamic aggregates feed carriers. Each pricing request requires single carrier.
 */
class DynamicConfig extends AbstractConfig
{
    /** @var \Packetery\Checkout\Model\Carrier */
    private $carrier;

    /**
     * @param array $data
     */
    public function __construct(Config $config, \Packetery\Checkout\Model\Carrier $carrier)
    {
        parent::__construct($config->toArray());
        $this->carrier = $carrier;
    }

    /**
     * @return bool
     */
    public function isActive(): bool {
        return parent::isActive() && !$this->carrier->getDeleted();
    }

    /**
     * @return \Magento\Framework\Phrase|string
     */
    public function getTitle() {
        return $this->carrier->getFinalCarrierName();
    }

    /**
     * @return array
     */
    public function getAllowedMethods(): array {
        return [ $this->carrier->getMethod() ];
    }

}
