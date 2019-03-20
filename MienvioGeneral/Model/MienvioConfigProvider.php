<?php

namespace MienvioMagento\MienvioGeneral\Model;


use MienvioMagento\MienvioGeneral\Helper\Data as Helper;
use \Magento\Checkout\Model\ConfigProviderInterface;
use \Magento\Store\Model\Information as Info;

class MienvioConfigProvider implements ConfigProviderInterface
{

    public function __construct(
        Helper $helperData,
        Info $storeInfo
    )
    {
        $this->_mienvioHelper = $helperData;
        $this->_storeInfo = $storeInfo;
    }

    public function getPhoneNumber()
    {
        return $this->_storeInfo->getStoreInformationObject()->getPhone();
}

    public function getConfig()
    {
        $storeInfo = [];
        $storeInfo['phone'] = $this->getPhoneNumber();

        $config = [];
        $config['mienvioApiKey'] = $this->_mienvioHelper->getMienvioApi();
        $config['storeInfo'] = $storeInfo;
        return $config;
    }
}