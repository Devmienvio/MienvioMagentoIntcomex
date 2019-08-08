<?php
namespace MienvioMagento\MienvioGeneral\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use MienvioMagento\MienvioGeneral\Helper\Data as Helper;



class Mienviorates extends AbstractCarrier implements CarrierInterface
{
    /**
     * Directory Helper
     * @var \Magento\Directory\Helper\Data
     */
    private $directoryHelper;

    const LEVEL_1_COUNTRIES = ['PE', 'CL'];

    /**
     * Defines if quote endpoint will be used at rates
     * @var boolean
     */
    const IS_QUOTE_ENDPOINT_ACTIVE = true;

    protected $_storeManager;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        \Magento\Directory\Helper\Data $directoryHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->_storeManager = $storeManager;
        $this->_code = 'mienviocarrier';
        $this->lbs_kg = 0.45359237;
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_logger = $logger;
        $this->_curl = $curl;
        $this->_mienvioHelper = $helperData;
        $this->directoryHelper = $directoryHelper;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Retrieve allowed methods
     *
     * @return string
     */
    public function getAllowedMethods()
    {
        return [
            $this->getCarrierCode() => __($this->getConfigData('name'))
        ];
    }

    /**
     * Checks if mienvio's configuration is ready
     *
     * @return boolean
     */
    private function checkIfMienvioEnvIsSet()
    {
        $isActive = $this->_mienvioHelper->isMienvioActive();
        $apiKey = $this->_mienvioHelper->getMienvioApi();
        $apiSource = $this->getConfigData('apikey');

        if (!$isActive) {
            return false;
        }

        if ($apiKey == "" || $apiSource == "NA") {
            return false;
        }
    }

    /**
     * Process full street string and retrieves street and suburb
     *
     * @param  string $fullStreet
     * @return array
     */
    private function processFullAddress($fullStreet)
    {
        $response = [
            'street' => '.',
            'suburb' => '.'
        ];

        if ($fullStreet != null && $fullStreet != "") {
            $fullStreetArray = explode("\n", $fullStreet);
            $count = count($fullStreetArray);

            if ($count > 0 && $fullStreetArray[0] !== false) {
                $response['street'] = $fullStreetArray[0];
            }

            if ($count > 1 && $fullStreetArray[1] !== false) {
                $response['suburb'] = $fullStreetArray[1];
            }
        }

        return $response;
    }

    /**
     * Retrieve rates for given shipping request
     *
     * @param  RateRequest $request
     * @return [type]               [description]
     */
    public function collectRates(RateRequest $request)
    {
        $rateResponse = $this->_rateResultFactory->create();
        $apiKey = $this->_mienvioHelper->getMienvioApi();
        $baseUrl =  $this->_mienvioHelper->getEnvironment();
        $createShipmentUrl  = $baseUrl . 'api/shipments';
        $quoteShipmentUrl   = $baseUrl . 'api/shipments/$shipmentId/rates';
        $getPackagesUrl     = $baseUrl . 'api/packages';
        $createAddressUrl   = $baseUrl . 'api/addresses';
        $createQuoteUrl     = $baseUrl . 'api/quotes';

        try {
            /* ADDRESS CREATION */
            $destCountryId  = $request->getDestCountryId();
            $destCountry    = $request->getDestCountry();
            $destRegion     = $request->getDestRegionId();
            $destRegionCode = $request->getDestRegionCode();
            $destFullStreet = $request->getDestStreet();
            $fullAddressProcessed = $this->processFullAddress($destFullStreet);
            $destCity       = $request->getDestCity();
            $destPostcode   = $request->getDestPostcode();

            $fromData = $this->createAddressDataStr(
                "MIENVIO DE MEXICO",
                $this->_mienvioHelper->getOriginStreet(),
                $this->_mienvioHelper->getOriginStreet2(),
                $this->_mienvioHelper->getOriginZipCode(),
                "ventas@mienvio.mx",
                "5551814040",
                '',
                $destCountryId
            );

            $toData = $this->createAddressDataStr(
                'usuario temporal',
                substr($fullAddressProcessed['street'], 0, 30),
                substr($fullAddressProcessed['suburb'], 0, 30),
                $destPostcode,
                "ventas@mienvio.mx",
                "5551814040",
                substr($fullAddressProcessed['suburb'], 0, 30),
                $destCountryId
            );

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];
            $this->_curl->setOptions($options);

            $this->_curl->post($createAddressUrl, json_encode($fromData));
            $addressFromResp = json_decode($this->_curl->getBody());
            $this->_logger->debug($this->_curl->getBody());
            $addressFromId = $addressFromResp->{'address'}->{'object_id'};

            $this->_curl->post($createAddressUrl, json_encode($toData));
            $addressToResp = json_decode($this->_curl->getBody());
            $this->_logger->debug($this->_curl->getBody());
            $addressToId = $addressToResp->{'address'}->{'object_id'};

            $itemsMeasures = $this->getOrderDefaultMeasures($request->getAllItems());
            $packageWeight = $this->convertWeight($request->getPackageWeight());

            if (self::IS_QUOTE_ENDPOINT_ACTIVE) {
                $rates = $this->quoteShipmentViaQuoteEndpoint(
                    $itemsMeasures['items'], $addressFromId, $addressToId, $createQuoteUrl
                );
            } else {
                $rates = $this->quoteShipment(
                    $itemsMeasures, $packageWeight, $getPackagesUrl,
                    $createShipmentUrl, $options, $packageValue, $fromZipCode);
            }

            foreach ($rates as $rate) {
                $this->_logger->debug('rate_id');
                $methodId = $rate['servicelevel'] . '-' . $rate['courier'];
                $this->_logger->debug((string)$methodId);
                $this->_logger->debug(strval($rate['id']));

                $method = $this->_rateMethodFactory->create();
                $method->setCarrier($this->getCarrierCode());
                $method->setCarrierTitle($rate['courier']);
                $method->setMethod((string)$methodId);
                $method->setMethodTitle($rate['servicelevel']);
                $method->setPrice($rate['cost']);
                $method->setCost($rate['cost']);
                $rateResponse->append($method);
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Rates Exception");
            $this->_logger->debug($e);
        }

        return $rateResponse;
    }

    /**
     * Quotes shipment using the quote endpoint
     *
     * @param  array $items
     * @param  integer $addressFromId
     * @param  integer $addressToId
     * @param  string $createQuoteUrl
     * @return string
     */
    private function quoteShipmentViaQuoteEndpoint($items, $addressFromId, $addressToId, $createQuoteUrl)
    {
        $quoteReqData = [
            'items'         => $items,
            'address_from'  => $addressFromId,
            'address_to'    => $addressToId,
            'shop_url'     => $this->_storeManager->getStore()->getUrl()
        ];

        $this->_logger->debug('Creating quote (mienviorates)', ['request' => json_encode($quoteReqData)]);
        $this->_curl->post($createQuoteUrl, json_encode($quoteReqData));
        $quoteResponse = json_decode($this->_curl->getBody());
        $this->_logger->debug('Creating quote (mienviorates)', ['response' => $this->_curl->getBody()]);

        if (isset($quoteResponse->{'rates'})) {
            $rates = [];

            foreach ($quoteResponse->{'rates'} as $rate) {
                $rates[] = [
                    'courier'      => $rate->{'provider'},
                    'servicelevel' => $rate->{'servicelevel'},
                    'id'           => $quoteResponse->{'quote_id'},
                    'cost'         => $rate->{'amount'},
                    'key'          => $rate->{'provider'} . '-' . $rate->{'servicelevel'}
                ];
            }

            return $rates;
        }

        return [[
            'courier'      => $quoteResponse->{'courier'},
            'servicelevel' => $quoteResponse->{'servicelevel'},
            'id'           => $quoteResponse->{'quote_id'},
            'cost'         => $quoteResponse->{'cost'}
        ]];
    }

    /**
     * Quotes shipment using given data
     *
     * @param  array $itemsMeasures
     * @param  float $packageWeight
     * @param  string $getPackagesUrl
     * @param  string $createShipmentUrl
     * @param  array $options
     * @param  float $packageValue
     * @param  string $fromZipCode
     * @return array
     */
    private function quoteShipment(
        $itemsMeasures, $packageWeight, $getPackagesUrl,
        $createShipmentUrl, $options, $packageValue, $fromZipCode)
    {
        $packageVolWeight = $itemsMeasures['vol_weight'];
        $orderLength      = $itemsMeasures['length'];
        $orderWidth       = $itemsMeasures['width'];
        $orderHeight      = $itemsMeasures['height'];
        $orderDescription = $itemsMeasures['description'];
        $numberOfPackages = 1;

        $packageVolWeight = ceil($packageVolWeight);
        $orderWeight      = $packageVolWeight > $packageWeight ? $packageVolWeight : $packageWeight;
        $orderDescription = substr($orderDescription, 0, 30);

        try {
            $packages = $this->getAvailablePackages($getPackagesUrl, $options);
            $packageCalculus = $this->calculateNeededPackage($orderWeight, $packageVolWeight, $packages);
            $chosenPackage   = $packageCalculus['package'];
            $numberOfPackages = $packageCalculus['qty'];

            $orderLength = $chosenPackage->{'length'};
            $orderWidth  = $chosenPackage->{'width'};
            $orderHeight = $chosenPackage->{'height'};
        } catch (\Exception $e) {
            $this->_logger->debug('Error when getting needed package', ['e' => $e]);
        }

        $this->_logger->debug('Order info', [
            'packageWeight' => $packageWeight,
            'volWeight'     => $packageVolWeight,
            'maxWeight'     => $orderWeight,
            'package'       => $chosenPackage,
            'description'   => $orderDescription,
            'numberOfPackages' => $numberOfPackages
        ]);

        $shipmentReqData = [
            'object_purpose' => 'QUOTE',
            'address_from'   => $addressFromId,
            'address_to'     => $addressToId,
            'weight'         => $orderWeight,
            'declared_value' => $packageValue,
            'description'    => $orderDescription,
            'source_type'    => 'api',
            'length'         => $orderLength,
            'width'          => $orderWidth,
            'height'         => $orderHeight
        ];

        $this->_curl->setOptions($options);
        $this->_curl->post($createShipmentUrl, json_encode($shipmentReqData));
        $shipmentResponse = json_decode($this->_curl->getBody());

        $shipmentId = $shipmentResponse->{'shipment'}->{'object_id'};

        $quoteShipmentUrl = str_replace('$shipmentId' , $shipmentId, $quoteShipmentUrl);
        $this->_curl->get($quoteShipmentUrl);
        $ratesResponse = json_decode($this->_curl->getBody());
        $responseArr = [];

        foreach ($ratesResponse->{'results'} as $rate) {
            if (is_object($rate)) {
                $responseArr[] = [
                    'courier'      => $rate->{'provider'},
                    'servicelevel' => $rate->{'servicelevel'},
                    'id'           => $rate->{'object_id'},
                    'cost'         => $rate->{'amount'}
                ];
            }
        }

        return $responseArr;
    }

    private function createQuoteFromItems($createQuoteUrl, $items, $addressFromId, $addressToId)
    {
        $quoteReqData = [
            'items' => $items,
            'address_from' => $addressFromId,
            'address_to' => $addressToId
        ];

        $this->_curl->post($createQuoteUrl, json_encode($quoteReqData));
        $quoteResponse = json_decode($this->_curl->getBody());

        $method = $this->_rateMethodFactory->create();
        $method->setCarrier($this->getCarrierCode());
        $method->setCarrierTitle($quoteResponse->{'courier'});
        $method->setMethodTitle($quoteResponse->{'servicelevel'});
        $method->setMethod($quoteResponse->{'quote_id'});
        $method->setPrice($rate->{'cost'});
        $method->setCost($rate->{'cost'});
        $rateResponse->append($method);

        return $rateResponse;
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
     * @param  string $countryCode
     * @return string
     */
    private function createAddressDataStr($name, $street, $street2, $zipcode, $email, $phone, $reference = '.', $countryCode)
    {
        $street = substr($street, 0, 35);
        $street2 = substr($street2, 0, 35);
        $name = substr($name, 0, 80);
        $phone = substr($phone, 0, 20);

        $data = [
            'object_type' => 'PURCHASE',
            'name' => $name,
            'street' => $street,
            'street2' => $street2,
            'email' => $email,
            'phone' => $phone,
            'reference' => $reference
        ];

        if ($countryCode === 'MX') {
            $data['zipcode'] = $zipcode;
        } else {
            $data['level_1'] = $zipcode;
        }

        return $data;
    }

    /**
     * Retrieves total measures of given items
     *
     * @param  Items $items
     * @return
     */
    private function getOrderDefaultMeasures($items)
    {
        $packageVolWeight = 0;
        $orderLength = 0;
        $orderWidth = 0;
        $orderHeight = 0;
        $orderDescription = '';
        $itemsArr = [];

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
            $itemsArr[] = [
                'id' => $item->getId(),
                'name' => $productName,
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight' => $weight,
                'volWeight' => $volWeight,
                'qty' => $item->getQty(),
                'declared_value' => $item->getprice(),
            ];
        }

        return [
            'vol_weight'  => $packageVolWeight,
            'length'      => $orderLength,
            'width'       => $orderWidth,
            'height'      => $orderHeight,
            'description' => $orderDescription,
            'items'       => $itemsArr
        ];
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
     * Retrieve user packages
     *
     * @param  string $baseUrl
     * @return array
     */
    private function getAvailablePackages($url, $options)
    {
        $this->_curl->setOptions($options);
        $this->_curl->get($url);
        $response = json_decode($this->_curl->getBody());
        $packages = $response->{'results'};

        return $packages;
    }

    /**
     * Retrieves weight in KG
     *
     * @param  float $_weigth
     * @return float
     */
    private function convertWeight($_weigth)
    {
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
}
