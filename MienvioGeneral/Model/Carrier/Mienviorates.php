<?php
namespace MienvioMagento\Services\Model\Carrier;

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

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Helper $helperData,
        array $data = []
    ) {
        $this->_code = 'ssi';
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_logger = $logger;
        $this->_curl = $curl;
        $this->_mienvioHelper = $helperData;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    public function collectRates(RateRequest $request)
    {
        //TODO: Add validation to get if the extension is enable
        $this->_logger->critical('Error message', ['test' => 1]);

        $result = $this->_rateResultFactory->create();

        $apiKey = $this->_mienvioHelper->getMienvioApi();
        $apiSource = $this->getConfigData('apisource');

        if ($apiKey == "") {
            return false;
        }

        try {
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
            $packageWeight = $request->getPackageWeight() * 1000;

            $url = 'https://api.starshipit.com/api/rates/shopify?apiKey=';
            $url = $url . $apiKey . '&integration_type=magento&source=' . $apiSource;
            $post_data = '{
                      "rate": {
                        "destination":{  
                          "country": "' . $destCountryId . '",
                          "postal_code": "' . $destPostcode . '",
                          "province": "' . $destRegionCode . '",
                          "city": "' . $destCity . '",
                          "name": null,
                          "address1": "' . $destStreet . '",
                          "address2": "' . $destSuburb . '",
                          "address3": null,
                          "phone": null,
                          "fax": null,
                          "address_type": null,
                          "company_name": null
                        },
                        "items":[
                          {
                            "name": "Total Items",
                            "sku": null,
                            "quantity": 1,
                            "grams": ' . $packageWeight . ' ,
                            "price": ' . $packageValue . ',
                            "vendor": null,
                            "requires_shipping": true,
                            "taxable": true,
                            "fulfillment_service": "manual"
                          }
                        ]
                      }
                    }';

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json'] ];
            $this->_curl->setOptions($options);
            $this->_curl->post($url, $post_data);
            $response = $this->_curl->getBody();

            $json_obj = json_decode($response);
            $rates_obj = $json_obj->{'rates'};
            $rates_count = count($rates_obj);
            if ($rates_count > 0) {
                foreach ($rates_obj as $rate) {
                    if (is_object($rate)) {
                        // Add shipping option with shipping price
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());
                        $method->setCarrierTitle($this->getConfigData('title'));
                        $method->setMethod($rate->{'service_code'});
                        $method->setMethodTitle($rate->{'service_name'});
                        $method->setPrice($rate->{'total_price'});
                        $method->setCost(0);
                        $result->append($method);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug("StarShipIT Rates Exception");
            $this->_logger->debug($e);
        }

        return $result;
    }

}