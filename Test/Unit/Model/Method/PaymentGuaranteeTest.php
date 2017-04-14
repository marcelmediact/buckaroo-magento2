<?php
/**
 *
 *          ..::..
 *     ..::::::::::::..
 *   ::'''''':''::'''''::
 *   ::..  ..:  :  ....::
 *   ::::  :::  :  :   ::
 *   ::::  :::  :  ''' ::
 *   ::::..:::..::.....::
 *     ''::::::::::::''
 *          ''::''
 *
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright   Copyright (c) Total Internet Group B.V. https://tig.nl/copyright
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
namespace TIG\Buckaroo\Test\Unit\Model\Method;

use Magento\Framework\App\Config;
use Magento\Framework\App\ProductMetadata;
use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice as MagentoInvoice;
use Magento\Sales\Model\Order\Payment;
use TIG\Buckaroo\Gateway\Http\TransactionBuilder\Order as TransactionBuilderOrder;
use TIG\Buckaroo\Gateway\Http\TransactionBuilderFactory;
use TIG\Buckaroo\Model\ConfigProvider\Method\Factory;
use TIG\Buckaroo\Model\ConfigProvider\Method\PaymentGuarantee as ConfigProviderPaymentGuarantee;
use TIG\Buckaroo\Model\Invoice;
use TIG\Buckaroo\Model\InvoiceFactory;
use TIG\Buckaroo\Model\Method\PaymentGuarantee;
use TIG\Buckaroo\Test\BaseTest;

class PaymentGuaranteeTest extends BaseTest
{
    protected $instanceClass = PaymentGuarantee::class;

    /**
     * @return array
     */
    public function assignDataProvider()
    {
        return [
            'no data' => [
                '2.0.5',
                []
            ],
            'version 2.0.0' => [
                '2.0.0',
                [
                    'termsCondition' => '1',
                    'customer_gender' => 'male',
                    'customer_billingName' => 'TIG',
                    'customer_DoB' => '01/01/1990',
                    'customer_iban' => 'NL12345'
                ]
            ],
            'version 2.1.0' => [
                '2.1.0',
                [
                    'additional_data' => [
                        'termsCondition' => '0',
                        'customer_gender' => 'female',
                        'customer_billingName' => 'TIG',
                        'customer_DoB' => '07/10/1990',
                        'customer_iban' => 'BE67890'
                    ]
                ]
            ],
            'incorrect DoB dateformat' => [
                '2.1.5',
                [
                    'additional_data' => [
                        'termsCondition' => '1',
                        'customer_gender' => 'female',
                        'customer_billingName' => 'TIG',
                        'customer_DoB' => '1990-01-01',
                        'customer_iban' => 'NL65498'
                    ]
                ]
            ],
        ];
    }

    /**
     * @param $version
     * @param $data
     *
     * @dataProvider assignDataProvider
     */
    public function testAssignData($version, $data)
    {
        $productMetadataMock = $this->getFakeMock(ProductMetadata::class)->setMethods(['getVersion'])->getMock();
        $productMetadataMock->expects($this->once())->method('getVersion')->willReturn($version);

        $dataObject = $this->getObject(DataObject::class);
        $dataObject->addData($data);

        $instance = $this->getInstance(['productMetadata' => $productMetadataMock]);

        $infoInstanceMock = $this->getFakeMock(InfoInterface::class)->getMock();
        $instance->setData('info_instance', $infoInstanceMock);

        $result = $instance->assignData($dataObject);
        $this->assertInstanceOf(PaymentGuarantee::class, $result);
    }

    /**
     * @return array
     */
    public function canCaptureProvider()
    {
        return [
            'can capture' => [
                'capture',
                true
            ],
            'can not capture' => [
                'order',
                false
            ]
        ];
    }

    /**
     * @param $paymentAction
     * @param $expected
     *
     * @dataProvider canCaptureProvider
     */
    public function testCanCapture($paymentAction, $expected)
    {
        $scopeConfigMock = $this->getFakeMock(Config::class)->setMethods(['getValue'])->getMock();
        $scopeConfigMock->expects($this->once())->method('getValue')->willReturn($paymentAction);

        $instance = $this->getInstance(['scopeConfig' => $scopeConfigMock]);
        $result = $instance->canCapture();

        $this->assertEquals($expected, $result);
    }

    public function testGetOrderTransactionBuilder()
    {
        $orderMock = $this->getOrderMock();

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);

        $instance = $this->getTransactionInstance();
        $result = $instance->getOrderTransactionBuilder($paymentMock);

        $this->assertInstanceOf(TransactionBuilderOrder::class, $result);
        $this->assertInstanceOf(Order::class, $result->getOrder());
        $this->assertEquals('TransactionRequest', $result->getMethod());

        $services = $result->getServices();
        $this->assertInternalType('array', $services);
        $this->assertEquals('paymentguarantee', $services['Name']);
        $this->assertEquals('PaymentInvitation', $services['Action']);
    }

    public function testGetCaptureTransactionBuilder()
    {
        $orderMock = $this->getOrderMock();

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);

        $instance = $this->getTransactionInstance();
        $result = $instance->getCaptureTransactionBuilder($paymentMock);

        $this->assertInstanceOf(TransactionBuilderOrder::class, $result);
        $this->assertInstanceOf(Order::class, $result->getOrder());
        $this->assertEquals('TransactionRequest', $result->getMethod());

        $services = $result->getServices();
        $this->assertInternalType('array', $services);
        $this->assertEquals('paymentguarantee', $services['Name']);
        $this->assertEquals('PartialInvoice', $services['Action']);
        $this->assertArrayHasKey('RequestParameter', $services);
    }

    public function testGetAuthorizeTransactionBuilder()
    {
        $orderMock = $this->getOrderMock();

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);

        $instance = $this->getTransactionInstance();
        $result = $instance->getAuthorizeTransactionBuilder($paymentMock);

        $this->assertInstanceOf(TransactionBuilderOrder::class, $result);
        $this->assertInstanceOf(Order::class, $result->getOrder());
        $this->assertEquals('TransactionRequest', $result->getMethod());

        $services = $result->getServices();
        $this->assertInternalType('array', $services);
        $this->assertEquals('paymentguarantee', $services['Name']);
        $this->assertEquals('Order', $services['Action']);
        $this->assertArrayHasKey('RequestParameter', $services);
    }

    public function testGetVoidTransactionBuilder()
    {
        $paymentMock = $this->getFakeMock(Payment::class)->getMock();
        $instance = $this->getInstance();

        $result = $instance->getVoidTransactionBuilder($paymentMock);
        $this->assertFalse($result);
    }

    public function testAfterCapture()
    {
        $invoiceMock = $this->getFakeMock(Invoice::class)->getMock();

        $invoiceFactoryMock = $this->getFakeMock(InvoiceFactory::class)->setMethods(['create'])->getMock();
        $invoiceFactoryMock->expects($this->once())->method('create')->willReturn($invoiceMock);

        $infoInstanceMock = $this->getFakeMock(InfoInterface::class)->getMock();
        $responseArray = [
            (Object)[
                'Invoice' => '123',
                'Key' => 'abc'
            ]
        ];

        $instance = $this->getInstance(['invoiceFactory' => $invoiceFactoryMock]);
        $result = $this->invokeArgs('afterCapture', [$infoInstanceMock, $responseArray], $instance);

        $this->assertInstanceOf(PaymentGuarantee::class, $result);
    }

    public function testGetPaymentGuaranteeRequestParameters()
    {
        $orderMock = $this->getOrderMock();

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);

        $instance = $this->getTransactionInstance();
        $result = $this->invokeArgs('getPaymentGuaranteeRequestParameters', [$paymentMock], $instance);

        $this->assertInternalType('array', $result);
        $this->assertGreaterThanOrEqual(19, count($result));
        $this->assertArrayHasKey('_', $result[0]);
        $this->assertArrayHasKey('Name', $result[0]);
    }

    /**
     * @return array
     */
    public function singleAddressProvider()
    {
        return [
            'address 1' => [
                [
                    'street' => 'Kabelweg 37',
                    'postcode' => '1014 BA',
                    'city' => 'Amsterdam',
                    'country_id' => 'NL'
                ],
                'INVOICE,SHIPPING',
                1,
                [
                    'AddressType' => 'INVOICE,SHIPPING',
                    'Street' => 'Kabelweg',
                    'HouseNumber' => '37',
                    'ZipCode' => '1014 BA',
                    'City' => 'Amsterdam',
                    'Country' => 'NL',
                ]
            ],
            'address 2' => [
                [
                    'street' => 'Hoofdstraat 80 1',
                    'postcode' => '8441ER',
                    'city' => 'Heerenveen',
                    'country_id' => 'NL'
                ],
                'SHIPPING',
                2,
                [
                    'AddressType' => 'SHIPPING',
                    'Street' => 'Hoofdstraat',
                    'HouseNumber' => '80',
                    'ZipCode' => '8441ER',
                    'City' => 'Heerenveen',
                    'Country' => 'NL',
                ]
            ]
        ];
    }

    /**
     * @param $addressData
     * @param $addressType
     * @param $addressId
     * @param $expected
     *
     * @dataProvider singleAddressProvider
     */
    public function testSingleAddress($addressData, $addressType, $addressId, $expected)
    {
        $address = $this->getObject(Address::class);
        $address->setData($addressData);

        $instance = $this->getInstance();
        $result = $this->invokeArgs('singleAddress', [$address, $addressType, $addressId], $instance);
        $this->assertEquals('address', $result[0]['Group']);
        $this->assertEquals('address_' . $addressId, $result[0]['GroupID']);

        foreach ($result as $resultItem) {
            $key = $resultItem['Name'];
            $this->assertEquals($expected[$key], $resultItem['_']);
        }
    }

    /**
     * @return array
     */
    public function isAddressDataDifferentProvider()
    {
        return [
            'is different' => [
                ['abc'],
                ['def'],
                true
            ],
            'is equal' => [
                ['ghi'],
                ['ghi'],
                false
            ]
        ];
    }

    /**
     * @param $dataOne
     * @param $dataTwo
     * @param $expected
     *
     * @dataProvider isAddressDataDifferentProvider
     */
    public function testIsAddressDataDifferent($dataOne, $dataTwo, $expected)
    {
        $instance = $this->getInstance();
        $result = $this->invokeArgs('isAddressDataDifferent', [$dataOne, $dataTwo], $instance);

        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function formatStreetProvider()
    {
        return [
            'street only' => [
                ['Kabelweg'],
                [
                    'street'          => 'Kabelweg',
                    'house_number'    => '',
                    'number_addition' => '',
                ]
            ],
            'with housenumber' => [
                ['Kabelweg 37'],
                [
                    'street'          => 'Kabelweg',
                    'house_number'    => '37',
                    'number_addition' => '',
                ]
            ],
            'with number addition' => [
                ['Kabelweg', '37 1'],
                [
                    'street'          => 'Kabelweg',
                    'house_number'    => '37',
                    'number_addition' => '1',
                ]
            ],
            'with letter addition' => [
                ['Kabelweg 37', 'A'],
                [
                    'street'          => 'Kabelweg',
                    'house_number'    => '37',
                    'number_addition' => 'A',
                ]
            ],
        ];
    }

    /**
     * @param $street
     * @param $expected
     *
     * @dataProvider formatStreetProvider
     */
    public function testFormatStreet($street, $expected)
    {
        $instance = $this->getInstance();
        $result = $this->invokeArgs('formatStreet', [$street], $instance);

        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function calculateInvoiceAmountProvider()
    {
        return [
            'no invoices' => [
                [],
                0
            ],
            'single invoice' => [
                [12.34],
                12.34
            ],
            'multiple invoices' => [
                [
                    56.78,
                    90.12,
                    34.56
                ],
                34.56
            ],
        ];
    }

    /**
     * @param $invoiceAmounts
     * @param $expected
     *
     * @dataProvider calculateInvoiceAmountProvider
     */
    public function testCalculateInvoiceAmount($invoiceAmounts, $expected)
    {
        $invoices = [];

        foreach ($invoiceAmounts as $amount) {
            $invoiceMock = $this->getFakeMock(MagentoInvoice::class)->setMethods(['getBaseGrandTotal'])->getMock();
            $invoiceMock->expects($this->any())->method('getBaseGrandTotal')->willReturn($amount);

            $invoices[] = $invoiceMock;
        }

        $orderMock = $this->getFakeMock(Order::class)->setMethods(['hasInvoices', 'getInvoiceCollection'])->getMock();
        $orderMock->expects($this->atLeastOnce())->method('hasInvoices')->willReturn(count($invoiceAmounts));
        $orderMock->expects($this->any())->method('getInvoiceCollection')->willReturn($invoices);

        $instance = $this->getInstance();
        $result = $this->invokeArgs('calculateInvoiceAmount', [$orderMock], $instance);

        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function calculateTaxAmountProvider()
    {
        return [
            'order tax' => [
                1.23,
                [],
                null,
                1.23
            ],
            'invoice tax' => [
                2.34,
                [
                    3.45,
                    4.56
                ],
                null,
                4.56
            ],
            'creditmemo tax' => [
                5.67,
                [
                    6.78,
                    7.89
                ],
                8.90,
                8.90
            ],
        ];
    }

    /**
     * @param $orderTax
     * @param $invoiceTaxes
     * @param $creditmemoTax
     * @param $expected
     *
     * @dataProvider calculateTaxAmountProvider
     */
    public function testCalculateTaxAmount($orderTax, $invoiceTaxes, $creditmemoTax, $expected)
    {
        $invoices = [];

        foreach ($invoiceTaxes as $amount) {
            $invoiceMock = $this->getFakeMock(MagentoInvoice::class)->setMethods(['getBaseTaxAmount'])->getMock();
            $invoiceMock->expects($this->any())->method('getBaseTaxAmount')->willReturn($amount);

            $invoices[] = $invoiceMock;
        }

        $orderMock = $this->getFakeMock(Order::class)
            ->setMethods(['hasInvoices', 'getInvoiceCollection', 'getBaseTaxAmount'])
            ->getMock();
        $orderMock->expects($this->atLeastOnce())->method('hasInvoices')->willReturn(count($invoiceTaxes));
        $orderMock->expects($this->any())->method('getInvoiceCollection')->willReturn($invoices);
        $orderMock->expects($this->once())->method('getBaseTaxAmount')->willReturn($orderTax);

        $creditmemoMock = $this->getFakeMock(Creditmemo::class)->setMethods(['getBaseTaxAmount'])->getMock();
        $creditmemoMock->expects($this->any())->method('getBaseTaxAmount')->willReturn($creditmemoTax);

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder', 'getCreditmemo'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);
        $expectsGetCreditmemo = $paymentMock->expects($this->once())->method('getCreditmemo');

        if ($creditmemoTax) {
            $expectsGetCreditmemo->willReturn($creditmemoMock);
        }

        $instance = $this->getInstance();
        $result = $this->invokeArgs('calculateTaxAmount', [$paymentMock], $instance);

        $this->assertInternalType('array', $result);
        $this->assertEquals($expected, $result[0]['_']);
    }

    /**
     * @return array
     */
    public function setCaptureTypeProvider()
    {
        return [
            'different amounts' => [
                1,
                2,
                1,
                true
            ],
            'multiple invoices' => [
                3,
                3,
                2,
                true
            ],
            'multiple invoices and different amounts' => [
                5,
                4,
                3,
                true
            ],
            'one invoice and same amounts' => [
                6,
                6,
                1,
                false
            ],
        ];
    }

    /**
     * @param $orderAmount
     * @param $invoiceAmount
     * @param $hasInvoices
     * @param $expected
     *
     * @dataProvider setCaptureTypeProvider
     */
    public function testSetCaptureType($orderAmount, $invoiceAmount, $hasInvoices, $expected)
    {
        $orderMock = $this->getFakeMock(Order::class)->setMethods(['getBaseGrandTotal', 'hasInvoices'])->getMock();
        $orderMock->expects($this->once())->method('getBaseGrandTotal')->willReturn($orderAmount);
        $orderMock->expects($this->any())->method('hasInvoices')->willReturn($hasInvoices);

        $instance = $this->getInstance();
        $this->invokeArgs('setCaptureType', [$orderMock, $invoiceAmount], $instance);

        $result = $this->getProperty('_isPartialCapture', $instance);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function isPartialRefundProvider()
    {
        return [
            'different amounts' => [
                1,
                2,
                1,
                true
            ],
            'multiple creditmemos' => [
                3,
                3,
                2,
                true
            ],
            'multiple creditmemos and different amounts' => [
                5,
                4,
                3,
                true
            ],
            'one creditmemo and same amounts' => [
                6,
                6,
                1,
                false
            ],
        ];
    }

    /**
     * @param $orderAmount
     * @param $creditmemoAmount
     * @param $hasCreditmemos
     * @param $expected
     *
     * @dataProvider isPartialRefundProvider
     */
    public function testIsPartialRefund($orderAmount, $creditmemoAmount, $hasCreditmemos, $expected)
    {
        $creditmemoMock = $this->getFakeMock(Creditmemo::class)->setMethods(['getBaseGrandTotal'])->getMock();
        $creditmemoMock->expects($this->once())->method('getBaseGrandTotal')->willReturn($creditmemoAmount);

        $orderMock = $this->getFakeMock(Order::class)->setMethods(['getBaseGrandTotal', 'hasCreditmemos'])->getMock();
        $orderMock->expects($this->once())->method('getBaseGrandTotal')->willReturn($orderAmount);
        $orderMock->expects($this->any())->method('hasCreditmemos')->willReturn($hasCreditmemos);

        $paymentMock = $this->getFakeMock(Payment::class)->setMethods(['getOrder', 'getCreditmemo'])->getMock();
        $paymentMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);
        $paymentMock->expects($this->atLeastOnce())->method('getCreditmemo')->willReturn($creditmemoMock);

        $instance = $this->getInstance();
        $result = $this->invokeArgs('isPartialRefund', [$paymentMock], $instance);

        $this->assertEquals($expected, $result);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getOrderMock()
    {
        $orderAddressMock = $this->getFakeMock(Address::class)
            ->setMethods(['getFirstName', 'getStreet', 'getData'])
            ->getMock();
        $orderAddressMock->expects($this->any())->method('getData')->willReturn([]);

        $orderMock = $this->getFakeMock(Order::class)->getMock();
        $orderMock->expects($this->once())->method('getBillingAddress')->willReturn($orderAddressMock);
        $orderMock->expects($this->once())->method('getShippingAddress')->willReturn($orderAddressMock);
        $orderMock->expects($this->atLeastOnce())->method('hasInvoices')->willReturn(false);

        return $orderMock;
    }

    /**
     * @return object
     */
    private function getTransactionInstance()
    {
        $transactionOrderMock = $this->getFakeMock(TransactionBuilderOrder::class)->setMethods(null)->getMock();

        $transactionBuilderMock = $this->getFakeMock(TransactionBuilderFactory::class)->setMethods(['get'])->getMock();
        $transactionBuilderMock->expects($this->any())->method('get')->willReturn($transactionOrderMock);

        $configGuaranteeMock = $this->getFakeMock(ConfigProviderPaymentGuarantee::class)->getMock();

        $configProviderMock = $this->getFakeMock(Factory::class)->setMethods(['get'])->getMock();
        $configProviderMock->expects($this->once())->method('get')->willReturn($configGuaranteeMock);

        $transactionInstance = $this->getInstance([
            'transactionBuilderFactory' => $transactionBuilderMock,
            'configProviderMethodFactory' => $configProviderMock
        ]);

        return $transactionInstance;
    }
}
