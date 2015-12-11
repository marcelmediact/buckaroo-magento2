<?php

/**
 *                  ___________       __            __
 *                  \__    ___/____ _/  |_ _____   |  |
 *                    |    |  /  _ \\   __\\__  \  |  |
 *                    |    | |  |_| ||  |   / __ \_|  |__
 *                    |____|  \____/ |__|  (____  /|____/
 *                                              \/
 *          ___          __                                   __
 *         |   |  ____ _/  |_   ____ _______   ____    ____ _/  |_
 *         |   | /    \\   __\_/ __ \\_  __ \ /    \ _/ __ \\   __\
 *         |   ||   |  \|  |  \  ___/ |  | \/|   |  \\  ___/ |  |
 *         |___||___|  /|__|   \_____>|__|   |___|  / \_____>|__|
 *                  \/                           \/
 *                  ________
 *                 /  _____/_______   ____   __ __ ______
 *                /   \  ___\_  __ \ /  _ \ |  |  \\____ \
 *                \    \_\  \|  | \/|  |_| ||  |  /|  |_| |
 *                 \______  /|__|    \____/ |____/ |   __/
 *                        \/                       |__|
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
 * @copyright   Copyright (c) 2015 Total Internet Group B.V. (http://www.tig.nl)
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */

namespace TIG\Buckaroo\Model;

use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use TIG\Buckaroo\Api\PushInterface;
use TIG\Buckaroo\Exception;
use \TIG\Buckaroo\Model\Validator\Push as ValidatorPush;
use \TIG\Buckaroo\Model\Method\AbstractMethod;
use \Magento\Framework\Pricing\Helper\Data as PricingHelper;

/**
 * Class Push
 *
 * @package TIG\Buckaroo\Model
 */
class Push implements PushInterface
{
    const ORDER_TYPE_COMPLETED  = 'complete';
    const ORDER_TYPE_CANCELED   = 'canceled';
    const ORDER_TYPE_HOLDED     = 'holded';
    const ORDER_TYPE_CLOSED     = 'closed';
    const ORDER_TYPE_PROCESSING = 'processing';
    const ORDER_TYPE_PENDING    = 'pending';

    /**
     * @var \Magento\Framework\Webapi\Rest\Request $request
     */
    protected $request;

    /**
     * @var \TIG\Buckaroo\Model\Validator\Push $_validator
     */
    protected $validator;

    /**
     * @var Order $order
     */
    protected $order;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Framework\Pricing\Helper\Data $pricingHelper
     */
    protected $pricingHelper;

    /**
     * @var array
     */
    protected $postData;

    /**
     * Push constructor.
     *
     * @param ObjectManagerInterface                              $objectManager
     * @param \Magento\Framework\Webapi\Rest\Request              $request
     * @param \TIG\Buckaroo\Model\Validator\Push                  $validator
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Framework\Pricing\Helper\Data              $pricingHelper
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Request $request,
        ValidatorPush $validator,
        OrderSender $orderSender,
        PricingHelper $pricingHelper
    ) {
        $this->objectManager = $objectManager;
        $this->request       = $request;
        $this->validator     = $validator;
        $this->orderSender   = $orderSender;
        $this->pricingHelper = $pricingHelper;

    }

    /**
     * {@inheritdoc}
     *
     * @todo Once Magento supports variable parameters, modify this method to no longer require a Request object.
     * @todo Debug mailing trough the push flow.
     */
    public function receivePush()
    {
        //Create post data array, change key values to lower case.
        $this->postData = array_change_key_case($this->request->getParams(), CASE_LOWER);
        //Validate status code and return response
        $response = $this->validator->validateStatusCode($this->postData['brq_statuscode']);
        //Check if the push can be procesed and if the order can be updtated.
        $validSignature = $this->validator->validateSignature($this->postData['brq_signature']);
        //Check if the order can recieve further status updates
        $this->order = $this->objectManager->create(Order::class)
            ->loadByIncrementId($this->postData['brq_invoicenumber']);
        if (!$this->order->getId()) {
            // try to get order by transaction id on payment.
            $this->order = $this->getOrderByTransactionKey($this->postData['brq_transactions']);
        }
        $canUpdateOrder = $this->canUpdateOrderStatus();

        //Last validation before push can be completed
        if (!$validSignature) {
            return false;
            //If the signature is valid but the order cant be updated, try to add a notification to the order comments.
        } elseif ($validSignature && !$canUpdateOrder) {
            $this->setOrderNotifactionNote($response['message']);
            return false;
        }
        //Make sure the transactions key is set.
        $payment     = $this->order->getPayment();
        $originalKey = AbstractMethod::BUCKAROO_ORIGINAL_TRANSACTION_KEY_KEY;

        if (!$payment->getAdditionalInformation($originalKey) && !empty($this->postData['brq_transactions'])
        ) {
            $payment->setAdditionalInformation($originalKey, $this->postData['brq_transactions']);
        }

        /**
         * @var  $newStates
         * @todo built the method getNewStatusCodes to replace the class constance values with config values.
         *
         */
        switch ($response['status']) {
            case 'TIG_BUCKAROO_STATUSCODE_TECHNICAL_ERROR':
            case 'TIG_BUCKAROO_STATUSCODE_VALIDATION_FAILURE':
            case 'TIG_BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT':
            case 'TIG_BUCKAROO_STATUSCODE_CANCELLED_BY_USER':
            case 'TIG_BUCKAROO_STATUSCODE_FAILED':
                $this->processFailedPush(self::ORDER_TYPE_CANCELED, $response['message']);
                break;
            case 'TIG_BUCKAROO_STATUSCODE_SUCCESS':
                $this->processSucceededPush(self::ORDER_TYPE_PROCESSING, $response['message']);
                break;
            case 'TIG_BUCKAROO_STATUSCODE_NEUTRAL':
                $this->setOrderNotifactionNote($response['message']);
                break;
            case 'TIG_BUCKAROO_STATUSCODE_PAYMENT_ON_HOLD':
            case 'TIG_BUCKAROO_STATUSCODE_WAITING_ON_CONSUMER':
            case 'TIG_BUCKAROO_STATUSCODE_PENDING_PROCESSING':
            case 'TIG_BUCKAROO_STATUSCODE_WAITING_ON_USER_INPUT':
                $this->processPendingPaymentPush(self::ORDER_TYPE_PENDING, $response['message']);
                break;
            case 'TIG_BUCKAROO_STATUSCODE_REJECTED':
                $this->processIncorrectPaymentPush(self::ORDER_TYPE_HOLDED, $response['message']);
                break;
        }

        return true;
    }

    /**
     * Sometimes the push does not contain the order id, when thats the case try to get the order by his payment,
     * by using its own transactionkey.
     *
     * @param $transactionId
     * @return bool
     */
    protected function getOrderByTransactionKey($transactionId)
    {
        if ($transactionId) {
            /** @var  \Magento\Sales\Model\Order\Payment\Transaction $transaction */
            $transaction = $this->objectManager->create('Magento\Sales\Model\Order\Payment\Transaction');
            $transaction->load($transactionId, 'txn_id');
            $order = $transaction->getOrder();
            if ($order) {
                return $order;
            }
        }
        return false;
    }

    /**
     * Checks if the order can be updated by checking his state and status.
     * @return bool
     */
    protected function canUpdateOrderStatus()
    {
        //Types of statusses
        $completedStateAndStatus = [self::ORDER_TYPE_COMPLETED, self::ORDER_TYPE_COMPLETED];
        $cancelledStateAndStatus = [self::ORDER_TYPE_CANCELED, self::ORDER_TYPE_CANCELED];
        $holdedStateAndStatus    = [self::ORDER_TYPE_HOLDED, self::ORDER_TYPE_HOLDED];
        $closedStateAndStatus    = [self::ORDER_TYPE_CLOSED, self::ORDER_TYPE_CLOSED];
        //Get current state and status of order
        $currentStateAndStatus = [$this->order->getState(), $this->order->getStatus()];
        //If the types are not the same and the order can receive an invoice the order can be udpated by BPE.
        if ($completedStateAndStatus != $currentStateAndStatus &&
           $cancelledStateAndStatus  != $currentStateAndStatus &&
           $holdedStateAndStatus     != $currentStateAndStatus &&
           $closedStateAndStatus     != $currentStateAndStatus &&
           $this->order->canInvoice()
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param $newStatus
     * @param $message
     *
     * @return bool
     */
    protected function processFailedPush($newStatus, $message)
    {
        //Create description
        $description = ''.$message;

        /**
         * @todo get config value cancel_on_failed
         */
        $buckarooCancelOnFailed = false;

        if ($this->order->canCancel() && $buckarooCancelOnFailed) {
            $this->order->cancel()->save();
        }

        $this->updateOrderStatus(Order::STATE_CANCELED, $newStatus, $description);

        return true;
    }

    /**
     * @param $newStatus
     * @param $message
     *
     * @return bool
     */
    protected function processSucceededPush($newStatus, $message)
    {
        if (!$this->order->getEmailSent()) {
            $this->orderSender->send($this->order);
        }

        //Create description
        $description = ''.$message;

        //Create invoice
        $this->saveInvoice();

        $this->updateOrderStatus(Order::STATE_PROCESSING, $newStatus, $description);

        return true;
    }

    /**
     * @param $newStatus
     * @param $message
     * @return bool
     */
    protected function processIncorrectPaymentPush($newStatus, $message)
    {
        $baseTotal = round($this->order->getBaseGrandTotal(), 0);

        //Set order amount
        $orderAmount  = $this->getCorrectOrderAmount();
        $description  = '<b> ' .$message .' :</b><br/>';
        /**
         * Determine whether too much or not has been paid
         */
        if ($baseTotal > $this->postData['brq_amount']) {
            $description .= __(
                'Not enough paid: %1 has been transfered. Order grand total was: %2.',
                $this->pricingHelper->currency($this->postData['brq_amount'], true, false),
                $this->pricingHelper->currency($orderAmount, true, false)
            );
        } elseif ($baseTotal < $this->postData['brq_amount']) {
            $description .= __(
                'Too much paid: %1 has been transfered. Order grand total was: %2.',
                $this->pricingHelper->currency($this->postData['brq_amount'], true, false),
                $this->pricingHelper->currency($orderAmount, true, false)
            );
        } else {
            return false;
        }

        //hold the order
        $this->order->hold()->save()->addStatusHistoryComment($description, $newStatus);
        $this->order->save();

        return true;
    }

    /**
     * @param $newStatus
     * @param $message
     *
     * @return bool
     */
    protected function processPendingPaymentPush($newStatus, $message)
    {
        $description = ''.$message;

        $this->updateOrderStatus(Order::STATE_NEW, $newStatus, $description);

        return true;
    }

    /**
     * Try to add an notifaction note to the order comments.
     * @todo make note available trought translations.
     * @todo What will be the notifactionnote ? -> Create an class that would create the note dynamic
     *
     * @param $message
     */
    protected function setOrderNotifactionNote($message)
    {
        $note  = 'Buckaroo attempted to update this order, but failed : ' .$message;
        try {
            $this->order->addStatusHistoryComment($note);
            $this->order->save();
        } catch (Exception $e) {
            // parse exception into debug mail
        }
    }

    /**
     * Updates the orderstate and add a comment.
     *
     * @param $orderState
     * @param $description
     * @param $newStatus
     */
    protected function updateOrderStatus($orderState, $newStatus, $description)
    {
        if ($this->order->getState() ==  $orderState) {
            $this->order->addStatusHistoryComment($description, $newStatus);
        } else {
            $this->order->addStatusHistoryComment($description);
        }
        $this->order->save();
    }

    /**
     * Creates and saves the invoice and adds for each invoice the buckaroo transaction keys
     *
     * @return bool
     */
    protected function saveInvoice()
    {
        //Only when the order can be invoiced and has not been invoiced before.
        if ($this->order->canInvoice() && !$this->order->hasInvoices()) {
            $payment = $this->order->getPayment();
            $payment->registerCaptureNotification($this->order->getBaseGrandTotal());
            $this->order->save();

            foreach ($this->order->getInvoiceCollection() as $invoice) {
                if (!isset($this->postData['brq_transactions'])) {
                    continue;
                }
                /** @var \Magento\Sales\Model\Order\Invoice  $invoice */
                $invoice->setTransactionId($this->postData['brq_transactions'])
                    ->save();
            }
            return true;
        }
        return false;
    }

    /**
     * Get Correct order amount
     * @return int $orderAmount
     */
    protected function getCorrectOrderAmount()
    {
        if ($this->postData['brq_currency'] == $this->order->getBaseCurrencyCode()) {
            $orderAmount = $this->order->getBaseGrandTotal();
        } else {
            $orderAmount = $this->order->getGrandTotal();
        }

        return $orderAmount;
    }
}
