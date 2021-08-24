<?php

namespace MienvioMagento\MienvioGeneral\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order;

class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var \Magento\Sales\Setup\SalesSetupFactory
     */
    protected $salesSetupFactory;

    /**
     * @param \Magento\Sales\Setup\SalesSetupFactory $salesSetupFactory
     */
    public function __construct(
        \Magento\Sales\Setup\SalesSetupFactory $salesSetupFactory
    ) {
        $this->salesSetupFactory = $salesSetupFactory;
    }

    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context){
        $setup->startSetup();
        echo $context->getVersion();
        if(version_compare($context->getVersion(), '3.1.7', '<')){

            $salesSetup = $this->salesSetupFactory->create(['resourceName' => 'sales_setup', 'setup' => $setup]);
            $salesSetup->addAttribute(Order::ENTITY, 'mienvio_trax_id', [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                'length'=> 50,
                'visible' => false,
                'nullable' => true
            ]);

            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order_grid'),
                'mienvio_trax_id',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                    'length' => 50,
                    'comment' =>'Trax ID of Carrier and service'
                ]
            );

        }

        $setup->endSetup();
    }
}