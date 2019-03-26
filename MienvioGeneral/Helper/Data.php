<?php

namespace MienvioMagento\MienvioGeneral\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;


class Data extends AbstractHelper
{


    const XML_PATH_GENERAL = 'mienviogeneral/';
    const XML_PATH_API_KEY = 'carriers/mienviocarrier/apikey';
    const XML_PATH_IS_ENABLE_MIENVIO = 'carriers/mienviocarrier/active';
    const XML_PATH_Street_store = 'shipping/origin/street_line2';
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

    public function getOriginAddress($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_Street_store , $storeId);
    }

}