<?php

namespace MienvioMagento\MienvioGeneral\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{


    const XML_PATH_GENERAL = 'mienviogeneral/';
    const XML_PATH_API_KEY = 'mienviogeneral/general/api';
    const XML_PATH_IS_ENABLE_MIENVIO = 'mienviogeneral/general/enable';

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

    public function isMienvioEnable($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_IS_ENABLE_MIENVIO , $storeId);
    }

    public function getMienvioApi($storeId = null)
    {
        return $this->getConfigValue(self::XML_PATH_API_KEY , $storeId);
    }

}