<?php

declare(strict_types=1);

namespace Packetery\Checkout\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Packetery\Checkout\Model\Carrier\Config\AbstractConfig;
use Packetery\Checkout\Model\Carrier\Config\AbstractMethodSelect;

/**
 * Use this service to extend custom carriers with new logic that is using dependencies. Good for avoiding constructor hell.
 */
abstract class AbstractBrain
{
    public const PREFIX = 'packetery';
    public const MULTI_SHIPPING_MODULE_NAME = 'multishipping';

    /** @var \Magento\Framework\App\Request\Http */
    protected $httpRequest;

    /** @var \Packetery\Checkout\Model\Pricing\Service */
    protected $pricingService;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    private $scopeConfig;

    /**
     * AbstractBrain constructor.
     *
     * @param \Magento\Framework\App\Request\Http $httpRequest
     * @param \Packetery\Checkout\Model\Pricing\Service $pricingService
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Framework\App\Request\Http $httpRequest,
        \Packetery\Checkout\Model\Pricing\Service $pricingService,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->httpRequest = $httpRequest;
        $this->pricingService = $pricingService;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param \Packetery\Checkout\Model\Carrier\AbstractCarrier $carrier
     * @return AbstractConfig
     */
    abstract public function createConfig(AbstractCarrier $carrier): AbstractConfig;


    /**
     * @param string $carrierCode
     * @param mixed $scope
     * @return mixed
     */
    protected function getConfigData(string $carrierCode, $scope) {
        $path = 'carriers/' . $carrierCode;

        return $this->scopeConfig->getValue(
            $path,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $scope
        );
    }

    /** Returns unique carrier identified in packetery context
     *
     * @return string
     */
    public static function getCarrierCodeStatic(): string {
        $reflection = new \ReflectionClass(static::class);
        $fileName = $reflection->getFileName();
        $carrierDir = basename(dirname($fileName));
        return lcfirst($carrierDir);
    }

    /**
     * @return \Packetery\Checkout\Model\Carrier\Config\AbstractMethodSelect
     */
    abstract public function getMethodSelect(): AbstractMethodSelect;

    /** Returns data that are used to figure out destination point id
     *
     * @return array
     */
    abstract protected static function getResolvableDestinationData(): array;


    public function resolvePointId(string $method, string $countryId): ?int {
        $data = $this::getResolvableDestinationData();
        return ($data[$method][$countryId] ?? null);
    }


    /** What branch ids does carrier implement
     * @return array
     */
    public static function getImplementedBranchIds(): array {
        return [];
    }


    public function collectRates(AbstractCarrier $carrier, RateRequest $request): ?Result {
        $config = $carrier->getPacketeryConfig();

        if (!$this->isCollectionPossible($config)) {
            return null;
        }

        $methods = [];
        foreach ($this->getFinalAllowedMethods($config, $this->getMethodSelect()) as $selectedMethod) {
            $methods[$selectedMethod] = $this->getMethodSelect()->getLabelByValue($selectedMethod);
        }

        return $this->pricingService->collectRates($request, $carrier->getCarrierCode(), $config, $methods);
    }

    /**
     * @param AbstractConfig $config
     * @return bool
     */
    public function isCollectionPossible(AbstractConfig $config): bool {
        if ($this->httpRequest->getModuleName() === self::MULTI_SHIPPING_MODULE_NAME) {
            return false;
        }

        if (!$config->isActive()) {
            return false;
        }

        return true;
    }

    /** dynamic carriers visible in configuration
     * @param bool $configurable
     * @param string $country
     * @param array $methods
     * @return \Packetery\Checkout\Model\Carrier[]
     */
    public function findConfigurableDynamicCarriers(string $country, array $methods): ?array {
        return null;
    }

    /** Static + dynamic countries
     * @param array $methods
     * @return array
     */
    abstract public function getAvailableCountries(array $methods): array;


    public function getFinalAllowedMethods(AbstractConfig $config, AbstractMethodSelect $methodSelect): array {
        $allowedMethods = $config->getAllowedMethods();
        if (empty($allowedMethods)) {
            return $methodSelect->getMethods();
        }

        return $allowedMethods;
    }
}
