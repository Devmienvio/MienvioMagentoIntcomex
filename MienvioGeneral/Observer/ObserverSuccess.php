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

            $fromData = $this->createAddressDataStr(
                $customerName,
                $this->_mienvioHelper->getOriginStreet(),
                $this->_mienvioHelper->getOriginStreet2(),
                $this->_mienvioHelper->getOriginZipCode(),
                $customermail,
                $customerPhone
            );


            $toStreet2 = empty($shippingAddress->getStreetLine(2)) ? $shippingAddress->getStreetLine(1) : $shippingAddress->getStreetLine(2);

            $toData = $this->createAddressDataStr(
                $customerName,
                $shippingAddress->getStreetLine(1),
                $toStreet2,
                $shippingAddress->getPostcode(),
                $customermail,
                $customerPhone,
                $shippingAddress->getStreetLine(3)
            );


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
            $orderDescription = '';
            $numberOfPackages = 1;

            foreach ($items as $item) {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $productName = $item->getName();
                $orderDescription .= $productName . ' ';
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

            $packageVolWeight = ceil($packageVolWeight);
            $orderWeight = $packageVolWeight > $realWeight ? $packageVolWeight : $realWeight;
            $orderDescription = substr($orderDescription, 0, 30);

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];

            try {
                $packages = $this->getAvailablePackages($baseUrl, $options);
                $packageCalculus = $this->calculateNeededPackage($orderWeight, $packageVolWeight, $packages);
                $chosenPackage = $packageCalculus['package'];
                $numberOfPackages = $packageCalculus['qty'];

                $orderLength = $chosenPackage->{'length'};
                $orderWidth = $chosenPackage->{'width'};
                $orderHeight = $chosenPackage->{'height'};
            } catch (\Exception $e) {
                $this->_logger->debug('Error when getting needed package', ['e' => $e]);
            }

            $this->_logger->debug('order info', [
                '$realWeight' => $realWeight,
                '$volWeight' => $packageVolWeight,
                '$maxWeight' => $orderWeight,
                'package' => $chosenPackage,
                'description' => $orderDescription,
                '$numberOfPackages' => $numberOfPackages
            ]);

            $postData = '{
                "object_purpose": "PURCHASE",
                "address_from": ' . $fromAddress . ',
                "address_to": ' . $toAddress . ',
                "weight": ' . $orderWeight . ',
                "declared_value": ' . $orderData['subtotal_incl_tax'] .',
                "description" : "' . $orderDescription .'",
                "source_type": "api",
                "length" :' . $orderLength  . ',
                "width": ' . $orderWidth . ',
                "height": ' . $orderHeight . ',
                "rate" :' . $shipping_id . ',
                "quantity" :' . $numberOfPackages . ',
                "order" : {
                    "marketplace" : "magento",
                    "object_id" : "' . $orderData['quote_id'] . '"
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
     * @param  float $orderVolWeight
     * @param  array $packages
     * @return array
     */
    private function calculateNeededPackage($orderWeight, $orderVolWeight, $packages)
    {
        $chosenPackVolWeight = 10000;
        $chosenPackage = null;
        $biggerPackage = null;
        $biggerPackageVolWeight = 0;
        $qty = 1;

        foreach ($packages as $package) {
            $packageVolWeight = $this->calculateVolumetricWeight(
                $package->{'length'}, $package->{'width'}, $package->{'height'}
            );

            if ($packageVolWeight > $biggerPackageVolWeight) {
                $biggerPackageVolWeight = $packageVolWeight;
                $biggerPackage = $package;
            }

            if ($packageVolWeight < $chosenPackVolWeight && $packageVolWeight >= $orderVolWeight) {
                $chosenPackVolWeight = $packageVolWeight;
                $chosenPackage = $package;
            }
        }

        if (is_null($chosenPackage)) {
            // then use bigger package
            $chosenPackage = $biggerPackage;
            $sizeRatio = $orderVolWeight/$biggerPackageVolWeight;
            $qty = ceil($sizeRatio);
        }

        return [
            'package' => $chosenPackage,
            'qty' => $qty
        ];
    }

    /**
     * Creates an string with the address data
     *
     * @param  string $name
     * @param  string $street
     * @param  string $street2
     * @param  string $zipcode
     * @param  string $email
     * @param  string $phone
     * @param  string $reference
     * @return string
     */
    private function createAddressDataStr($name, $street, $street2, $zipcode, $email, $phone, $reference = '.')
    {
        $street = substr($street, 0, 35);
        $street2 = substr($street2, 0, 35);
        $name = substr($name, 0, 80);
        $phone = substr($phone, 0, 20);

        return '{
            "object_type": "PURCHASE",
            "name": "'. $name . '",
            "street": "'. $street . '",
            "street2": "'. $street2 . '",
            "zipcode": '. $zipcode . ',
            "email": "'. $email .'",
            "phone": "'. $phone .'",
            "reference": "'. $reference .'"
            }';
    }
}
