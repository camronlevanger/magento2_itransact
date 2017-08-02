<?php
/**
 * iTransact payment method model
 *
 * @package     CamronLevanger\iTransact
 * @author      Camron G. Levanger
 * @copyright   Camron G. Levanger
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace CamronLevanger\iTransact\Model;

class Payment extends \Magento\Payment\Model\Method\Cc
{
    const CODE = 'itransact';

    protected $_code = self::CODE;

    protected $_isGateway                   = true;
    protected $_canCapture                  = true;
    protected $_canCapturePartial           = true;
    protected $_canRefund                   = true;
    protected $_canRefundInvoicePartial     = true;

    protected $_iTransactKey = false;

    protected $_countryFactory;

    protected $_minAmount = null;
    protected $_maxAmount = null;
    protected $_supportedCurrencyCodes = array('USD');

    protected $_debugReplacePrivateDataKeys = ['number', 'exp_month', 'exp_year', 'cvc'];

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        array $data = array()
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $moduleList,
            $localeDate,
            null,
            null,
            $data
        );

        $this->_countryFactory = $countryFactory;


        $this->_iTransactKey = $this->getConfigData('api_key');

        $this->_minAmount = $this->getConfigData('min_order_total');
        $this->_maxAmount = $this->getConfigData('max_order_total');
    }

    /**
     * Payment capturing
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        //throw new \Magento\Framework\Validator\Exception(__('Inside Stripe, throwing donuts :]'));

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        /** @var \Magento\Sales\Model\Order\Address $billing */
        $billing = $order->getBillingAddress();

        try {
            $requestData = [
                'amount'        => $amount * 100,
                'currency'      => strtolower($order->getBaseCurrencyCode()),
                'description'   => sprintf('#%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),
                'card'          => [
                    'number'            => $payment->getCcNumber(),
                    'exp_month'         => sprintf('%02d',$payment->getCcExpMonth()),
                    'exp_year'          => $payment->getCcExpYear(),
                    'cvv'               => $payment->getCcCid(),
                    'name'              => $billing->getName(),
                    // To get full localized country name, use this instead:
                    // 'address_country'   => $this->_countryFactory->create()->loadByCode($billing->getCountryId())->getName(),
                ],
                'address' => [
                    'line1'     => $billing->getStreetLine(1),
                    'line2'     => $billing->getStreetLine(2),
                    'city'      => $billing->getCity(),
                    'postal_code'       => $billing->getPostcode(),
                    'state'     => $billing->getRegion(),
               ]
            ];

            $key = $this->_iTransactKey;

            #Using built in PHP5 functions
            $digest = hash_hmac('sha1', $requestData, $key, true);
            $signature = base64_encode($digest);

            $client = new \GuzzleHttp\Client();
            $headers = ['Authorization' => $signature];
            $body = $requestData;
            $res = $client->request('POST', 'https://api.itransact.com/transactions', $headers, $body);

            if ($res->getStatusCode() != 201) {
                 $this->debugData(['request' => $requestData, 'exception' => $e->getMessage()]);
                 $this->_logger->error(__('Payment capturing error.'));
                 throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
            } else {

                //$charge = \Stripe\Charge::create($requestData);
            $payment
                ->setTransactionId($res->getBody()["id"])
                ->setIsTransactionClosed(0);
            }

        } catch (\Exception $e) {
            $this->debugData(['request' => $requestData, 'exception' => $e->getMessage()]);
            $this->_logger->error(__('Payment capturing error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment capturing error.'));
        }

        return $this;
    }

    /**
     * Payment refund
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Validator\Exception
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $transactionId = $payment->getParentTransactionId();

        try {
            \Stripe\Charge::retrieve($transactionId)->refund(['amount' => $amount * 100]);
        } catch (\Exception $e) {
            $this->debugData(['transaction_id' => $transactionId, 'exception' => $e->getMessage()]);
            $this->_logger->error(__('Payment refunding error.'));
            throw new \Magento\Framework\Validator\Exception(__('Payment refunding error.'));
        }

        $payment
            ->setTransactionId($transactionId . '-' . \Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND)
            ->setParentTransactionId($transactionId)
            ->setIsTransactionClosed(1)
            ->setShouldCloseParentTransaction(1);

        return $this;
    }

    /**
     * Determine method availability based on quote amount and config data
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && (
            $quote->getBaseGrandTotal() < $this->_minAmount
            || ($this->_maxAmount && $quote->getBaseGrandTotal() > $this->_maxAmount))
        ) {
            return false;
        }

        if (!$this->getConfigData('api_key')) {
            return false;
        }

        return parent::isAvailable($quote);
    }

    /**
     * Availability for currency
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_supportedCurrencyCodes)) {
            return false;
        }
        return true;
    }

    /**
     * Assign data to info model instance
     *
     * @param \Magento\Framework\DataObject|mixed $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        if (!$data instanceof \Magento\Framework\DataObject) {
            $data = new \Magento\Framework\DataObject($data);
        }

        $info = $this->getInfoInstance();
        $info->setCcType(
            $data->getDataByPath('additional_data/cc_type')
        )->setCcOwner(
            $data->getDataByPath('additional_data/cc_owner')
        )->setCcLast4(
            substr($data->getDataByPath('additional_data/cc_number'), -4)
        )->setCcNumber(
            $data->getDataByPath('additional_data/cc_number')
        )->setCcCid(
            $data->getDataByPath('additional_data/cc_cid')
        )->setCcExpMonth(
            $data->getDataByPath('additional_data/cc_exp_month')
        )->setCcExpYear(
            $data->getDataByPath('additional_data/cc_exp_year')
        )->setCcSsIssue(
            $data->getCcSsIssue()
        )->setCcSsStartMonth(
            $data->getCcSsStartMonth()
        )->setCcSsStartYear(
            $data->getCcSsStartYear()
        );
        return $this;
    }
}