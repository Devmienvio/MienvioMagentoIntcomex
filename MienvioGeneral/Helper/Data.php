<?php

namespace MienvioMagento\MienvioGeneral\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;


class Data extends AbstractHelper
{


    const XML_PATH_GENERAL = 'mienviogeneral/';
    const XML_PATH_API_KEY = 'carriers/mienviocarrier/apikey';
    const XML_PATH_API_KEY_RED = 'carriers/mienviocarrier/apikeyredundant';
    const XML_PATH_IS_ENABLE_MIENVIO = 'carriers/mienviocarrier/active';
    const XML_PATH_ENVIRONMENT = 'carriers/mienviocarrier/environment';
    const XML_MEASURES = 'carriers/mienviocarrier/measures';
    const XML_ESD_CAT = 'carriers/mienviocarrier/esdcat';
    const XML_FILTER_CHEAPERCOST = 'carriers/mienviocarrier/filtercheapercost';
    const XML_PATH_FREE_SHIPPING = 'carriers/mienviocarrier/freeshipping';
    const XML_PATH_TITLE_METHOD_FREE = 'carriers/mienviocarrier/titlemethodfree';
    const XML_PATH_SERVICE_LEVEL = 'carriers/mienviocarrier/servicelevel';
    const XML_PATH_PROVIDER = 'carriers/mienviocarrier/provider';
    const XML_PATH_LOCATION = 'carriers/mienviocarrier/location';
    const XML_PATH_Street_store = 'shipping/origin/street_line1';
    const XML_PATH_Street2_store = 'shipping/origin/street_line2';
    const XML_PATH_ZipCode_store = 'shipping/origin/postcode';
    const XML_PATH_city_store = 'shipping/origin/city';
    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $field, ScopeInterface::SCOPE_STORE, $storeId
        );
    }

    public function getGeneralConfig($code, $storeId = null)
    {

        return $this->getConfigValue(self::XML_PATH_GENERAL .'general/'. $code, $storeId);
    }

    public function isMienvioActive($storeId = null)
    {
        return (boolean)$this->getConfigValue(self::XML_PATH_IS_ENABLE_MIENVIO , $storeId);
    }
    public function isFreeShipping($storeId = null)
    {
        return (boolean)$this->getConfigValue(self::XML_PATH_FREE_SHIPPING , $storeId);
    }
    public function getTitleMethodFree($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_TITLE_METHOD_FREE , $storeId);
    }
    public function getServiceLevel($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_SERVICE_LEVEL , $storeId);
    }
    public function getProvider($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_PROVIDER , $storeId);
    }
    public function getLocation($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_LOCATION , $storeId);
    }

    public function getEsdList($storeId = null)
    {
        return $this->getConfigValue(self::XML_ESD_CAT, $storeId);
    }

    public function getFilterListByCost($storeId = null)
    {
        return $this->getConfigValue(self::XML_FILTER_CHEAPERCOST, $storeId);
    }

    public function getEnvironment($storeId = null)
    {
        $env = $this->getConfigValue(self::XML_PATH_ENVIRONMENT , $storeId);
        $result = '';
        switch ($env){
            case 0://Production
                $result = 'https://app.mienvio.mx/';
                break;
            case 1: //Sandbox
                $result = 'https://sandboxenterprise.mienvio.mx/';
                break;
            case 2:// Develop
                $result = 'https://sandboxenterprise.mienvio.mx/';
                break;
            default:
                $result = 'https://sandboxenterprise.mienvio.mx/';
                break;
        }
        return $result;

    }

    public function getMienvioApi($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_API_KEY , $storeId);
    }

    public function getMienvioApiRedundant($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_API_KEY_RED , $storeId);
    }

    public function getOriginStreet($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_Street_store , $storeId);
    }
    public function getOriginStreet2($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_Street2_store , $storeId);
    }
    public function getOriginZipCode($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_ZipCode_store , $storeId);
    }

    public function getOriginCity($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_city_store , $storeId);
    }
    public function getMeasures($storeId = null)
    {
        $measures = $this->getConfigValue(self::XML_MEASURES , $storeId);
        $result = '';
        switch ($measures){
            case 0://Sistema Imperial
                $result = 0;
                break;
            case 1: //Sistema Internacional
                $result = 1;
                break;
            default:
                $result = 0;
                break;
        }
        return $result;

    }

}