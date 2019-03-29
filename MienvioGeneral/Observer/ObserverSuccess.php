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
            $orderData = $order->getData();

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

            /* Measures */
            $realWeight = $this->convertWeight($orderData['weight']);
            $items = $order->getAllItems();
            $packageVolWeight = 0;

            $orderLength = 0;
            $orderWidth = 0;
            $orderHeight = 0;

            foreach ($items as $item) {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $productName = $item->getName();
                $product = $objectManager->create('Magento\Catalog\Model\Product')->loadByAttribute('name', $productName);

                $length = $this->convertInchesToCms($product->getData('ts_dimensions_length'));
                $width  = $this->convertInchesToCms($product->getData('ts_dimensions_width'));
                $height = $this->convertInchesToCms($product->getData('ts_dimensions_height'));
                $weight = $this->convertWeight($product->getData('weight'));

                $orderLength += $length;
                $orderWidth  += $width;
                $orderHeight += $height;

                $volWeight = $this->calculateVolumetricWeight($length, $width, $height);
                $packageVolWeight += $volWeight;

                $this->_logger->debug('product',
                ['id' => $item->getId(), 'name' => $productName,
                '$length' => $length, '$width' => $width,
                '$height' => $height, '$weight' => $weight, '$volWeight' => $volWeight]);
            }

            $orderWeight = $packageVolWeight > $realWeight ? $packageVolWeight : $realWeight;

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];

            try {
                $packages = $this->getAvailablePackages($baseUrl, $options);
                $chosenPackage = $this->calculateNeededPackage($orderWeight, $packages);

                $orderLength = $chosenPackage->{'length'};
                $orderWidth = $chosenPackage->{'width'};
                $orderHeight = $chosenPackage->{'height'};
            } catch (\Exception $e) {
                $this->_logger->debug('Error when getting needed package', ['e' => $e]);
            }

            $this->_logger->debug('product', ['$realWeight' => $realWeight,'$volWeight' => $packageVolWeight, '$maxWeight' => $orderWeight, 'package' => $chosenPackage]);


            $postData = '{
                "object_purpose": "PURCHASE",
                "address_from": ' . $fromAddress . ',
                "address_to": ' . $toAddress . ',
                "weight": ' . $orderWeight . ',
                "description": "Articulos varios",
                "declared_value": ' . $orderData['subtotal_incl_tax'] .',
                "source_type": "api",
                "length" :' . $orderLength  . ',
                "width": ' . $orderWidth . ',
                "height": ' . $orderHeight . ',
                "rate" :' . $shipping_id . ',
                "order" : {
                    "marketplace" : "magento",
                    "object_id" : ' . strval($orderData['quote_id']) . '
                }
            }';

            $this->_logger->info('orderObject', ["data" => $postData]);

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

    /**
     * Retrieve user packages
     *
     * @param  string $baseUrl
     * @return array
     */
    private function getAvailablePackages($baseUrl, $options)
    {
        $url = $baseUrl . 'api/packages';
        $this->_curl->setOptions($options);
        $this->_curl->get($url);
        $response = $this->_curl->getBody();
        $json_obj = json_decode($response);
        $packages = $json_obj->{'results'};

        $this->_logger->debug("packages", ["packages" => $packages]);

        return $packages;
    }

    /**
     * Calculates volumetric weight of given measures
     *
     * @param  float $length
     * @param  float $width
     * @param  float $height
     * @return float
     */
    private function calculateVolumetricWeight($length, $width, $height)
    {
        $volumetricWeight = round(((1 * $length * $width * $height) / 5000), 4);

		return $volumetricWeight;
    }

    /**
     * Retrieves weight in KG
     *
     * @param  float $_weigth
     * @return float
     */
    private function convertWeight($_weigth)
    {
        return ceil($_weigth * 0.45359237);

        $storeWeightUnit = $this->directoryHelper->getWeightUnit();
        $weight = 0;
        switch ($storeWeightUnit) {
            case 'lbs':
                $weight = $_weigth * $this->lbs_kg;
                break;
            case 'kgs':
                $weight = $_weigth;
                break;
        }

        return ceil($weight);
    }

    /**
     * Convert inches to cms
     *
     * @param  float $inches
     * @return float
     */
    private function convertInchesToCms($inches)
    {
        return $inches * 2.54;
    }

    /**
     * Calculates needed package size for order items
     *
     * @param  float $orderWeight
     * @param  array $packages
     * @return array
     */
    private function calculateNeededPackage($orderWeight, $packages)
    {
        $choosenPackVolWeight = 10000;
        $choosenPackage = null;

        foreach ($packages as $package) {
            $packageVolWeight = $this->calculateVolumetricWeight(
                $package->{'length'}, $package->{'width'}, $package->{'height'}
            );

            if ($packageVolWeight < $choosenPackVolWeight && $packageVolWeight >= $orderWeight) {
                $choosenPackVolWeight = $packageVolWeight;
                $choosenPackage = $package;
            }
        }

        return $choosenPackage;
    }
}
