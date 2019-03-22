<?php

namespace MienvioMagento\MienvioGeneral\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Customer\Model\Address\Config;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\DataObject;

class Data extends AbstractHelper
{


    const XML_PATH_GENERAL = 'mienviogeneral/';
    const XML_PATH_API_KEY = 'carriers/mienviocarrier/apikey';
    const XML_PATH_IS_ENABLE_MIENVIO = 'carriers/mienviocarrier/active';

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

    public function getMienvioApi($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_API_KEY , $storeId);
    }

}