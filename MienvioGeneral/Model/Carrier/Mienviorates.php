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

    private $directoryHelper;
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

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    public function collectRates(RateRequest $request)
    {
        $isActive = $this->_mienvioHelper->isMienvioActive();

        if (!$isActive) {
            return false;
        }

        $result = $this->_rateResultFactory->create();

        $apiKey = $this->_mienvioHelper->getMienvioApi();
        $apiSource = $this->getConfigData('apikey');
        $baseUrl =  $this->_mienvioHelper->getEnvironment();
        if ($apiKey == "" || $apiSource == "NA") {
            return false;
        }

        try {
            /* Location data */
            $destCountryId = $request->getDestCountryId();
            $destCountry = $request->getDestCountry();
            $destRegion = $request->getDestRegionId();
            $destRegionCode = $request->getDestRegionCode();
            $destFullStreet = $request->getDestStreet();
            $destStreet = "";
            $destSuburb = "";
            $destCity = $request->getDestCity();

            $destPostcode = $request->getDestPostcode();

            if ($destFullStreet != null && $destFullStreet != "") {
                $destFullStreetArray = explode("\n", $destFullStreet);
                $count = count($destFullStreetArray);
                if ($count > 0 && $destFullStreetArray[0] !== false) {
                    $destStreet = $destFullStreetArray[0];
                }
                if ($count > 1 && $destFullStreetArray[1] !== false) {
                    $destSuburb = $destFullStreetArray[1];
                }
            }

            $packageValue = $request->getPackageValue();
            $packageWeight = $request->getPackageWeight();
            $fromZipCode = $request->getPostcode();
            $realWeight = $this->convertWeight($packageWeight);

            $items = $request->getAllItems();
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

            // Call Api to create rutes
            $url = $baseUrl . 'api/shipments';
            $post_data = '{
                 "object_purpose": "QUOTE",
                 "zipcode_from": ' . $fromZipCode . ',
                 "zipcode_to": ' . $destPostcode . ',
                 "weight": ' . $orderWeight . ',
                 "declared_value": ' . $packageValue .',
                 "description" : "' . $orderDescription .'",
                 "source_type" : "api",
                 "length" :' . $orderLength  . ',
                 "width": ' . $orderWidth . ',
                 "height": ' . $orderHeight . '
            }';

            $this->_logger->debug("postdata", ["postdata" => $post_data]);

            $this->_curl->setOptions($options);
            $this->_curl->post($url, $post_data);
            $response = $this->_curl->getBody();
            $json_obj = json_decode($response);

            $this->_logger->debug("response", ["data" => $json_obj]);

            $shipmentId = $json_obj->{'shipment'}->{'object_id'};
            $this->_curl->get($url . '/'.$shipmentId. '/rates?limit=1000000');
            $responseRates = $this->_curl->getBody();
            $json_obj_rates = json_decode($responseRates);
            $totalCount = $json_obj_rates->{'total_count'};
            $this->_logger->debug("rates", ["rates" => $json_obj_rates]);

            if ($totalCount > 0 ) {
                $rates_obj =  $json_obj_rates->{'results'};

                foreach ($rates_obj as $rate) {
                    if (is_object($rate)) {
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());
                        $method->setCarrierTitle($rate->{'provider'});
                        $method->setMethod($rate->{'object_id'});
                        $method->setMethodTitle($rate->{'servicelevel'});
                        $method->setPrice($rate->{'amount'} * $numberOfPackages);
                        $method->setCost($rate->{'amount'} * $numberOfPackages);
                        $result->append($method);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug("Rates Exception");
            $this->_logger->debug($e);
        }
        return $result;
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
