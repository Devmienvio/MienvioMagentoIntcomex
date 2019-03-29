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
        try {
            $baseUrl =  $this->_mienvioHelper->getEnvironment();
            $order = $observer->getEvent()->getOrder();
            $Carriers = $shipping_id;
            $order->setMienvioCarriers($Carriers);
            $orderId = $order->getId();
            $apiKey = $this->_mienvioHelper->getMienvioApi();

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

            $fromZipCode =  $this->_mienvioHelper->getOriginStreet();
            $this->_logger->info("cc", ["cc" => $fromZipCode]);

            // Logic to create address
            $addressUrl = $baseUrl . 'api/addresses';
            $fromData = '{
                "object_type": "PURCHASE",
                "name": "'. $customerName . '",
                "street": "'. $this->_mienvioHelper->getOriginStreet() . '",
                "street2": "'. $this->_mienvioHelper->getOriginStreet2() . '",
                "zipcode": '. $this->_mienvioHelper->getOriginZipCode() . ',
                "email": "'. $customermail .'",
                "phone": "'. $customerPhone .'",
                "reference": ""
                }';

            $toStreet2 = empty($shippingAddress->getStreetLine(2)) ? $shippingAddress->getStreetLine(1) : $shippingAddress->getStreetLine(2);

            $toData = '{
                "object_type": "PURCHASE",
                "name": "'.$customerName.'",
                "street": "'. $shippingAddress->getStreetLine(1).'",
                "street2":  "'. $toStreet2 .'",
                "zipcode": '.$shippingAddress->getPostcode().',
                "email": "'.$customermail.'",
                "phone": "'.$customerPhone.'",
                "reference": ""
                }
            ';

            $this->_logger->info("obje", ["toData" => $toData,"fromData" => $fromData]);

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];
            $this->_curl->setOptions($options);

            $this->_curl->post($addressUrl, $fromData);
            $responseFROM = $this->_curl->getBody();
            $json_obj_from = json_decode($responseFROM);
            $fromAddress = $json_obj_from->{'address'}->{'object_id'};

            $this->_curl->post($addressUrl, $toData);
            $responseTO = $this->_curl->getBody();
            $json_obj_to = json_decode($responseTO);
            $toAddress = $json_obj_to->{'address'}->{'object_id'};


            $this->_logger->info("responses", ["to" => $toAddress,"from" => $fromAddress]);

            $postData = '{
                "object_purpose": "PURCHASE",
                "address_from": ' . $fromAddress . ',
                "address_to": ' . $toAddress . ',
                "weight": ' . $orderWeight . ',
                "description": Articulos varios,
                "declared_value": ' . $packageValue .',
                "source_type": "api",
                "length" :' . $orderLength  . ',
                "width": ' . $orderWidth . ',
                "height": ' . $orderHeight . '
                "rate" :' . $shipping_id . '
            }';

            $this->_logger->info('orderObject', ["data" => $orderObject]);

            $this->_curl->post($baseUrl . '/api/shipments', $postData);
            $response = $this->_curl->getBody();
            $json_obj = json_decode($response);

            $this->_logger->info('shipment PURCHASE', ["data" => $json_obj]);
        } catch (\Exception $e) {
            $this->_logger->info("error saving new shipping method Exception");
            $this->_logger->info($e->getMessage());
        }

        return $this;
    }
}
