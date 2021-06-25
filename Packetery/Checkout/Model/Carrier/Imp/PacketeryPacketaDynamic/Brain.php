<?php

declare(strict_types=1);

namespace Packetery\Checkout\Model\Carrier\Imp\PacketeryPacketaDynamic;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Packetery\Checkout\Model\Carrier\AbstractCarrier;
use Packetery\Checkout\Model\Carrier\Config\AbstractConfig;
use Packetery\Checkout\Model\Carrier\Config\AbstractMethodSelect;
use Packetery\Checkout\Model\Carrier\Methods;

class Brain extends \Packetery\Checkout\Model\Carrier\AbstractBrain
{
    /** @var MethodSelect */
    private $methodSelect;

    /** @var \Packetery\Checkout\Model\ResourceModel\Carrier\CollectionFactory */
    private $carrierCollectionFactory;

    /** @var \Magento\Shipping\Model\Rate\ResultFactory */
    private $rateResultFactory;

    /**
     * Brain constructor.
     *
     * @param \Magento\Framework\App\Request\Http $httpRequest
     * @param \Packetery\Checkout\Model\Pricing\Service $pricingService
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param MethodSelect $methodSelect
     * @param \Packetery\Checkout\Model\ResourceModel\Carrier\CollectionFactory $carrierCollectionFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     */
    public function __construct(
      \Magento\Framework\App\Request\Http $httpRequest,
      \Packetery\Checkout\Model\Pricing\Service $pricingService,
      \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
      MethodSelect $methodSelect,
      \Packetery\Checkout\Model\ResourceModel\Carrier\CollectionFactory $carrierCollectionFactory,
      \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
    ) {
        parent::__construct($httpRequest, $pricingService, $scopeConfig);
        $this->methodSelect = $methodSelect;
        $this->carrierCollectionFactory = $carrierCollectionFactory;
        $this->rateResultFactory = $rateResultFactory;
    }

    /**
     * @param \Packetery\Checkout\Model\Carrier\AbstractCarrier $carrier
     * @return Config
     */
    public function createConfig(\Packetery\Checkout\Model\Carrier\AbstractCarrier $carrier): AbstractConfig
    {
        return new Config($this->getConfigData($carrier->getCarrierCode(), $carrier->getStore()));
    }

    /**
     * @return \Magento\Shipping\Model\Rate\Result
     */
    public function createRateResult(): \Magento\Shipping\Model\Rate\Result {
        return $this->rateResultFactory->create();
    }

    /** Represents all possible methods for all dynamic carriers
     *
     * @return MethodSelect
     */
    public function getMethodSelect(): \Packetery\Checkout\Model\Carrier\Config\AbstractMethodSelect {
        return $this->methodSelect;
    }

    /**
     * @inheridoc
     */
    protected static function getResolvableDestinationData(): array {
        return [];
    }

    /**
     * @return bool
     */
    public function isAssignableToPricingRule(): bool {
        return false;
    }


    public function getDynamicCarrierById(int $dynamicCarrierId): ?\Packetery\Checkout\Model\Carrier {

        return $this->carrierCollectionFactory->create()->getItemByColumnValue('carrier_id', $dynamicCarrierId);
    }

    /**
     * @return array
     */
    public function findResolvableDynamicCarriers(): array {
        /** @var \Packetery\Checkout\Model\ResourceModel\Carrier\Collection $collection */
        $collection = $this->carrierCollectionFactory->create();
        $collection->resolvableOnly();
        $collection->whereCarrierIdNotIn(\Packetery\Checkout\Model\Carrier\Facade::getAllImplementedBranchIds());
        return $collection->getItems();
    }

    /**
     * @param string $country
     * @param array $methods
     * @return \Packetery\Checkout\Model\Carrier[]
     */
    public function findConfigurableDynamicCarriers(string $country, array $methods): array {
        /** @var \Packetery\Checkout\Model\ResourceModel\Carrier\Collection $collection */
        $collection = $this->carrierCollectionFactory->create();
        $collection->configurableOnly();
        $collection->whereCountry($country);
        $collection->forDeliveryMethods($methods);
        $collection->whereCarrierIdNotIn(\Packetery\Checkout\Model\Carrier\Facade::getAllImplementedBranchIds());

        return $collection->getItems();
    }


    public function resolvePointId(string $method, string $countryId, ?\Packetery\Checkout\Model\Carrier $dynamicCarrier = null): ?int {
        if ($dynamicCarrier === null) {
            throw new \Exception('Dynamic carrier was not passed');
        }

        if ($this->validateDynamicCarrier($method, $countryId, $dynamicCarrier) === false) {
            return null;
        }

        return $dynamicCarrier->getCarrierId();
    }


    public function updateDynamicCarrierName(string $carrierName, \Packetery\Checkout\Model\Carrier $dynamicCarrier): void {
        $collection = $this->carrierCollectionFactory->create();
        $collection->addFilter('carrier_id', $dynamicCarrier->getCarrierId());
        $collection->setDataToAll(
            [
                'carrier_name' => $carrierName,
            ]
        );
        $collection->save();
    }


    public function validateDynamicCarrier(string $method, string $countryId, \Packetery\Checkout\Model\Carrier $dynamicCarrier): bool {
        if ($dynamicCarrier->getDeleted() === true) {
            return false;
        }

        // todo: nevím, jestli můžu smazat, obávám se, že se tímto způsobem rozhoduje v collectRates a resolvePoint jestli je dyn carrier dostupný pro zadanou zemi
        if ($dynamicCarrier->getCountry() !== $countryId) {
            return false;
        }

        if ($method !== $dynamicCarrier->getMethod()) {
            return false;
        }

        return true;
    }

    /**
     * @param array $methods
     * @return array
     */
    public function getAvailableCountries(array $methods): array {
        /** @var \Packetery\Checkout\Model\ResourceModel\Carrier\Collection $collection */
        $collection = $this->carrierCollectionFactory->create();
        $collection->forDeliveryMethods($methods);
        return $collection->getColumnValues('country');
    }


    public function collectRatesDynamic(AbstractCarrier $carrier, RateRequest $request, \Packetery\Checkout\Model\Carrier $dynamicCarrier): ?Result
    {
        /** @var Config $config */
        $config = $carrier->getPacketeryConfig();

        $dynamicConfig = new DynamicConfig(
          $config,
          $dynamicCarrier
        );

        if (!$this->isCollectionPossible($dynamicConfig)) {
            return null;
        }

        $methods = [];
        foreach ($this->getFinalAllowedMethodsDynamic($config, $dynamicConfig, $this->getMethodSelect()) as $selectedMethod) {
            if ($this->isAvailableForCollection($selectedMethod, $request->getDestCountryId(), $dynamicCarrier) === false) {
                continue;
            }

            $methods[$selectedMethod] = $this->getMethodSelect()->getLabelByValue($selectedMethod);
        }

        return $this->pricingService->collectRates($request, $carrier->getCarrierCode(), $dynamicConfig, $methods, ($dynamicCarrier ? $dynamicCarrier->getCarrierId() : null));
    }

    protected function isAvailableForCollection(string $method, string $countryId, \Packetery\Checkout\Model\Carrier $dynamicCarrier): bool
    {
        if ($method !== Methods::PICKUP_POINT_DELIVERY) {
            if ($this->resolvePointId($method, $countryId, $dynamicCarrier) === null) {
                return false;
            }
        }

        $availableCountries = $this->getAvailableCountries([$method]);
        return in_array($countryId, $availableCountries, true) && $this->validateDynamicCarrier($method, $countryId, $dynamicCarrier);
    }


    public function getFinalAllowedMethodsDynamic(Config $config, DynamicConfig $dynamicConfig, AbstractMethodSelect $methodSelect): array {
        $final = $this->getFinalAllowedMethods($config, $methodSelect);
        return array_intersect($dynamicConfig->getAllowedMethods(), $final);
    }
}
