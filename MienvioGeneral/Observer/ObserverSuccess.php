<?php
namespace MienvioMagento\MienvioGeneral\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\ResourceModel\Quote\Address\Rate\CollectionFactory;
use Magento\Quote\Model\QuoteRepository;
use Psr\Log\LoggerInterface;
use MienvioMagento\MienvioGeneral\Helper\Data as Helper;

class ObserverSuccess implements ObserverInterface
{
    private $collectionFactory;
    private $quoteRepository;
    const XML_PATH_Street_store = 'shipping/origin/street_line2';

    public function __construct(
        CollectionFactory $collectionFactory,
        QuoteRepository $quoteRepository,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        LoggerInterface $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->quoteRepository = $quoteRepository;
        $this->_code = 'mienviocarrier';
        $this->_api = 'http://localhost:8000/';
        $this->_logger = $logger;
        $this->_mienvioHelper = $helperData;
        $this->_curl = $curl;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getData('order');
        $shippingMethodObject = $order->getShippingMethod(true);
        $shipping_id = $shippingMethodObject->getMethod();

        if ($shippingMethodObject->getCarrierCode() != $this->_code) {
            return $this;
        }
        // Logic to save orders in mienvio api
        try{
            $url = 'api/shipments';
            $order = $observer->getEvent()->getOrder();
            $Carriers = $shipping_id;
            $order->setMienvioCarriers($Carriers);
            $orderId = $order->getId();
            $this->_logger->info("order_id", ["order_id" => $orderId]);

            $quoteId = $order->getQuoteId();

            if ($quoteId === null) {
                return $this;
            }

            $quote = $this->quoteRepository->get($quoteId);

            $shippingAddress = $quote->getShippingAddress();

            if ($shippingAddress === null) {
                return $this;
            }
            $this->_logger->info("data", ["data" => $shippingAddress->getData()]);
            $this->_logger->info("order", ["order" => $order->getData()]);
            $customerName= $shippingAddress->getName();
            $customermail= $shippingAddress->getEmail();
            $customerPhone= $shippingAddress->getTelephone();
            $fromZipCode =  $this->_mienvioHelper->getOriginAddress();
            $this->_logger->info("cc", ["cc" => $fromZipCode]);
            // Logic to create address
            $addressUrl = $this->_api . '/api/addresses';
            $fromData = '{
                "object_type": "PURCHASE",
                "name": "'.$customerName.'",
                "street": to.street,
                "street2": to.street2,
                "zipcode": to.zipcode,
                "email": "'.$customermail.'",
                "phone": '.$customerPhone.'",
                "reference": ""
                }
            ';
            $toData = '{
                "object_type": "PURCHASE",
                "name": "'.$customerName.'",
                "street": "'. $shippingAddress->getStreetFull().'",
                "street2": "",
                "zipcode": '.$shippingAddress->getPostcode().'",
                "email": "'.$customermail.'",
                "phone": '.$customerPhone.'",
                "reference": ""
                }
            ';
            $this->_logger->info("obje", ["toData" => $toData,"fromData" => $fromData]);

        } catch (\Exception $e) {
            $this->_logger->info("error saving new shipping method Exception");
            $this->_logger->info($e->getMessage());
        }

        return $this;
    }
}
