<?php

declare(strict_types=1);

namespace Packetery\Checkout\Model\Carrier;

use Packetery\Checkout\Model\HybridCarrier;

class Facade
{
    /** @var \Magento\Shipping\Model\CarrierFactory */
    private $carrierFactory;

    /** @var \Magento\Shipping\Model\Config */
    private $shippingConfig;

    /**
     * @param \Magento\Shipping\Model\CarrierFactory $carrierFactory
     * @param \Magento\Shipping\Model\Config $shippingConfig
     */
    public function __construct(
        \Magento\Shipping\Model\CarrierFactory $carrierFactory,
        \Magento\Shipping\Model\Config $shippingConfig
    ) {
        $this->carrierFactory = $carrierFactory;
        $this->shippingConfig = $shippingConfig;
    }


    public function updateCarrierName(string $carrierName, string $carrierCode, int $carrierId): void
    {
        $carrier = $this->getMagentoCarrier($carrierCode);

        /** @var \Packetery\Checkout\Model\Carrier\Imp\PacketeryPacketaDynamic\Brain $dynamicBrain */
        $dynamicBrain = $carrier->getPacketeryBrain();

        /** @var \Packetery\Checkout\Model\Carrier $dynamicCarrier */
        $dynamicCarrier = $dynamicBrain->getDynamicCarrierById($carrierId);

        $dynamicBrain->updateDynamicCarrierName($carrierName, $dynamicCarrier);
    }

    /**
     * @param string $carrierCode
     * @param int|null $dynamicCarrierId
     * @param string $method
     * @param string $country
     * @return \Packetery\Checkout\Model\HybridCarrier
     */
    public function createHybridCarrier(string $carrierCode, ?int $dynamicCarrierId, string $method, string $country): HybridCarrier
    {
        $carrier = $this->getMagentoCarrier($carrierCode);

        if ($dynamicCarrierId) {
            $dynamicCarrier = $this->getDynamicCarrier($carrier, $dynamicCarrierId);

            if ($dynamicCarrier !== null) {
                return HybridCarrier::fromAbstractDynamic($carrier, $dynamicCarrier, $method, $country);
            }
        }

        return HybridCarrier::fromAbstract($carrier, $method, $country);
    }

    /**
     * @param string $carrierCode
     * @param $carrierId
     * @return bool
     */
    public function isDynamicCarrier(string $carrierCode, $carrierId): bool
    {
        $carrier = $this->getMagentoCarrier($carrierCode);

        if (is_numeric($carrierId)) {
            $dynamicCarrier = $this->getDynamicCarrier($carrier, (int)$carrierId);
            if ($dynamicCarrier !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return AbstractCarrier[]
     */
    public function getPacketeryAbstractCarriers(): array
    {
        return array_filter(
            $this->shippingConfig->getAllCarriers(),
            static function ($carrier) {
                return $carrier instanceof AbstractCarrier;
            }
        );
    }

    /**
     * @return array
     */
    public function getAllAvailableCountries(): array
    {
        $countries = [];

        foreach ($this->getPacketeryAbstractCarriers() as $carrier) {
            $carrierMethods = Methods::getAll();
            $countries = array_merge($countries, $carrier->getPacketeryBrain()->getAvailableCountries($carrierMethods));
        }

        return array_unique($countries);
    }


    private function getDynamicCarrier(\Packetery\Checkout\Model\Carrier\Imp\PacketeryPacketaDynamic\Carrier $carrier, int $dynamicCarrierId): ?\Packetery\Checkout\Model\Carrier
    {
        return $carrier->getPacketeryBrain()->getDynamicCarrierById($dynamicCarrierId);
    }

    /**
     * @param string $carrierCode
     * @return \Packetery\Checkout\Model\Carrier\AbstractCarrier
     */
    private function getMagentoCarrier(string $carrierCode): AbstractCarrier
    {
        return $this->carrierFactory->get($carrierCode);
    }

    /**
     * @return array
     */
    public static function getAllImplementedBranchIds(): array
    {
        $branchIds = [];
        $dirs = glob(__DIR__ . '/Imp/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $name = basename($dir);
            /** @var \Packetery\Checkout\Model\Carrier\AbstractBrain $className */
            $className = '\\Packetery\\Checkout\\Model\\Carrier\\Imp\\' . $name . '\\Brain';
            $branchIds = array_merge($branchIds, $className::getImplementedBranchIds());
        }

        return $branchIds;
    }


    public function getMaxWeight(string $carrierCode): ?float
    {
        $carrier = $this->getMagentoCarrier($carrierCode);
        return $carrier->getPacketeryConfig()->getMaxWeight();
    }
}
