<?php
namespace MienvioMagento\MienvioGeneral\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\CollectionFactory;
use Magento\Quote\Model\QuoteRepository;
use Psr\Log\LoggerInterface;

class ObserverSuccess implements ObserverInterface
{
    private $collectionFactory;
    private $quoteRepository;

    public function __construct(
        CollectionFactory $collectionFactory,
        QuoteRepository $quoteRepository,
        \Magento\Framework\HTTP\Client\Curl $curl,
        LoggerInterface $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->_code = 'mienviocarrier';
        $this->_logger = $logger;
        $this->_curl = $curl;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getData('order');
        $shippingMethodObject = $order->getShippingMethod(true);
        $statuscode = $observer->getEvent()->getOrder()->getStatus();
        $statuslabel = $observer->getEvent()->getOrder()->getStatusLabel();

        $this->_logger->info("statuslabel", ["statuslabel" => $statuslabel]);
        $this->_logger->info("SalesOrderPlaceAfter", ["SalesOrderPlaceAfter" => $shippingMethodObject->getCarrierCode()]);

        if ($shippingMethodObject->getCarrierCode() != $this->_code) {
            return $this;
        }

        return $this;
    }
}
