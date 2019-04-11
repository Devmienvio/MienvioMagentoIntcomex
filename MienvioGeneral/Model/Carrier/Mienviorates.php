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

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        \Magento\Directory\Helper\Data $directoryHelper,
        array $data = []
    ) {
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
            'street' => '',
            'suburb' => ''
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

        try {
            /* Location data */
            $destCountryId = $request->getDestCountryId();
            $destCountry = $request->getDestCountry();
            $destRegion = $request->getDestRegionId();
            $destRegionCode = $request->getDestRegionCode();
            $destFullStreet = $request->getDestStreet();
            $fullAddressProcessed = $this->processFullAddress($destFullStreet);
            $destCity = $request->getDestCity();
            $destPostcode = $request->getDestPostcode();

            $this->_logger->debug('Shop address info', [
                'destCountryId' => $destCountryId,
                'destCountry'   => $destCountry,
                'destRegion'    => $destRegion,
                'destRegionCode' => $destRegionCode,
                'destFullStreet' => $destFullStreet,
                'destStreet'    => $fullAddressProcessed['street'],
                'destSuburb'    => $fullAddressProcessed['suburb'],
                'destCity'      => $destCity
            ]);


            $itemsMeasures = $this->getOrderDefaultMeasures($request->getAllItems());
            $packageWeight = $this->convertWeight($request->getPackageWeight());
            $packageVolWeight = $itemsMeasures['vol_weight'];
            $orderLength = $itemsMeasures['length'];
            $orderWidth  = $itemsMeasures['width'];
            $orderHeight = $itemsMeasures['height'];
            $orderDescription = $itemsMeasures['description'];
            $numberOfPackages = 1;

            $packageVolWeight = ceil($packageVolWeight);
            $orderWeight = $packageVolWeight > $packageWeight ? $packageVolWeight : $packageWeight;
            $orderDescription = substr($orderDescription, 0, 30);

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$apiKey}"]];

            try {
                $packages = $this->getAvailablePackages($baseUrl, $options);
                $packageCalculus = $this->calculateNeededPackage($orderWeight, $packageVolWeight, $packages);
                $chosenPackage   = $packageCalculus['package'];
                $numberOfPackages = $packageCalculus['qty'];

                $orderLength = $chosenPackage->{'length'};
                $orderWidth  = $chosenPackage->{'width'};
                $orderHeight = $chosenPackage->{'height'};
            } catch (\Exception $e) {
                $this->_logger->debug('Error when getting needed package', ['e' => $e]);
            }

            $packageValue = $request->getPackageValue();
            $fromZipCode = $request->getPostcode();

            $this->_logger->debug('order info', [
                '$packageWeight' => $packageWeight,
                '$volWeight' => $packageVolWeight,
                '$maxWeight' => $orderWeight,
                'package' => $chosenPackage,
                'description' => $orderDescription,
                '$numberOfPackages' => $numberOfPackages
            ]);

            // Call Api to create rutes
            $createShipmentUrl = $baseUrl . 'api/shipments';
            $quoteShipmentUrl = $baseUrl . 'api/shipments/$shipmentId/rates';

            $jsonPostData = [
                'object_purpose' => 'QUOTE',
                'weight' => $orderWeight,
                'declared_value' => $packageValue,
                'description' => $orderDescription,
                'source_type' => 'api',
                'length' => $orderLength,
                'width' => $orderWidth,
                'height' => $orderHeight
            ];

            if (in_array($destCountryId, self::LEVEL_1_COUNTRIES)) {
                $jsonPostData['from_level1'] = $fromZipCode;
                $jsonPostData['to_level1'] = $destPostcode;
            } else {
                $jsonPostData['zipcode_from'] = $fromZipCode;
                $jsonPostData['zipcode_to'] = $destPostcode;
            }

            $this->_logger->debug("postdata", ["postdata" => $jsonPostData]);

            $this->_curl->setOptions($options);
            $this->_curl->post($createShipmentUrl, json_encode($jsonPostData));
            $shipmentResponse = json_decode($this->_curl->getBody());

            $this->_logger->debug("Create shipment:", ["data" => $shipmentResponse]);

            $shipmentId = $shipmentResponse->{'shipment'}->{'object_id'};

            $quoteShipmentUrl = str_replace('$shipmentId' , $shipmentId, $quoteShipmentUrl);
            $this->_curl->get($quoteShipmentUrl);
            $ratesResponse = json_decode($this->_curl->getBody());

            $totalCount = $ratesResponse->{'total_count'};
            $this->_logger->debug("Retrieved Rates:", ["rates" => $ratesResponse]);

            if ($totalCount > 0 ) {
                foreach ($ratesResponse->{'results'} as $rate) {
                    if (is_object($rate)) {
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());
                        $method->setCarrierTitle($rate->{'provider'});
                        $method->setMethod($rate->{'object_id'});
                        $method->setMethodTitle($rate->{'servicelevel'});
                        $method->setPrice($rate->{'amount'} * $numberOfPackages);
                        $method->setCost($rate->{'amount'} * $numberOfPackages);
                        $rateResponse->append($method);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Rates Exception");
            $this->_logger->debug($e);
        }
        return $rateResponse;
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

            $this->_logger->debug('product',[
                'id' => $item->getId(),
                'name' => $productName,
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight' => $weight,
                'volWeight' => $volWeight
            ]);
        }

        return [
            'vol_weight'  => $packageVolWeight,
            'length'      => $orderLength,
            'width'       => $orderWidth,
            'height'      => $orderHeight,
            'description' => $orderDescription
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
