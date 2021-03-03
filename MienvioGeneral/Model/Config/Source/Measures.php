<?php

namespace MienvioMagento\MienvioGeneral\Model\Config\Source;

class Measures implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('Sistema Ingles (lb,in)')],
            ['value' => 1, 'label' => __('Sistema Internacional (kg,m)')]
        ];
    }
}