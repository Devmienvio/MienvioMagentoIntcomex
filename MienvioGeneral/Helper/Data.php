<?php

namespace MienvioMagento\MienvioGeneral\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;


class Data extends AbstractHelper
{


    const XML_PATH_GENERAL = 'mienviogeneral/';
    const XML_PATH_API_KEY = 'carriers/mienviocarrier/apikey';
    const XML_PATH_IS_ENABLE_MIENVIO = 'carriers/mienviocarrier/active';
    const XML_PATH_ENVIRONMENT = 'carriers/mienviocarrier/environment';
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

    public function getEnvironment($storeId = null)
    {
        $env = $this->getConfigValue(self::XML_PATH_IS_ENABLE_MIENVIO , $storeId);
        $result = '';
        switch ($env){
            case 0://Production
                $result = 'https://app.mienvio.mx/';
                break;
            case 1: //Sandbox
                $result = 'http://sandbox.mienvio.mx/';
                break;
            case 2:// Develop
                $result = 'http://localhost:8000/';
                break;
            default:
                $result = 'https://app.mienvio.mx/';
                break;
        }
        return $result;

    }

    public function getMienvioApi($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_API_KEY , $storeId);
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

}