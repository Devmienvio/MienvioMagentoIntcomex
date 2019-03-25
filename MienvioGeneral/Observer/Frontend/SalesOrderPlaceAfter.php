<?php
namespace MienvioMagento\MienvioGeneral\Observer\Frontend;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Maurisource\MageShip\Model\ResourceModel\Rates\CollectionFactory as RateFactory;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\CollectionFactory;
use Magento\Quote\Model\QuoteRepository;

class SalesOrderPlaceAfter implements ObserverInterface
{
    private $collectionFactory;
    private $rateFactory;
    private $rateRepository;
    private $quoteRepository;

    public function __construct(
        CollectionFactory $collectionFactory,
        QuoteRepository $quoteRepository
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->_code = 'mienviocarrier';

    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getData('order');

        $shippingMethodObject = $order->getShippingMethod(true);

        if ($shippingMethodObject->getCarrierCode() != $this->_code) {
            return $this;
        }

        if ($shippingMethodObject->getMethod() == \Maurisource\MageShip\Model\Carrier::DEFAULT_METHOD) {
            return $this;
        }

        return $this;
    }
}
