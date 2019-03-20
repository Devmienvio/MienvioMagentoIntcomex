<?php

namespace MienvioMagento\MienvioGeneral\Model;


use MienvioMagento\MienvioGeneral\Helper\Data as Helper;
use \Magento\Checkout\Model\ConfigProviderInterface;

class MienvioConfigProvider implements ConfigProviderInterface
{

    public function __construct(
        Helper $helperData
    )
    {
        $this->mienvioHelper = $helperData;
    }

    public function getConfig()
    {
        $config = [];
        $config['mienvioApiKey'] = $this->mienvioHelper->getMienvioApi();
        return $config;
    }
}