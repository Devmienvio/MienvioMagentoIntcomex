<?php

namespace MienvioMagento\MienvioGeneral\Model\Config\Source;

class Environments implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('Produccion')],
            ['value' => 1, 'label' => __('Sandbox')],
            ['value' => 2, 'label' => __('Desarrollo')],
        ];
    }
}