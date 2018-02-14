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
 * to servicedesk@totalinternetgroup.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@totalinternetgroup.nl for more information.
 *
 * @copyright Copyright (c) 2016 Total Internet Group B.V. (http://www.totalinternetgroup.nl)
 * @license   http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
namespace TIG\Buckaroo\Model\Checkout\Plugin;

use Magento\Checkout\Api\AgreementsValidatorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\CheckoutAgreements\Api\CheckoutAgreementsRepositoryInterface;
use Magento\Checkout\Api\PaymentInformationManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\CheckoutAgreements\Model\Checkout\Plugin\Validation as MagentoValidation;

/**
 * Class Validation
 */
class Validation extends MagentoValidation
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfiguration;

    /**
     * @var CheckoutAgreementsRepositoryInterface
     */
    protected $checkoutAgreementsRepository;

    /**
     * @var AgreementsValidatorInterface
     */
    protected $agreementsValidator;

    /**
     * @param AgreementsValidatorInterface $agreementsValidator
     * @param ScopeConfigInterface $scopeConfiguration
     * @param CheckoutAgreementsRepositoryInterface $checkoutAgreementsRepository
     */
    public function __construct(
        AgreementsValidatorInterface $agreementsValidator,
        ScopeConfigInterface $scopeConfiguration,
        CheckoutAgreementsRepositoryInterface $checkoutAgreementsRepository
    ) {
        $this->agreementsValidator = $agreementsValidator;
        $this->scopeConfiguration = $scopeConfiguration;
        $this->checkoutAgreementsRepository = $checkoutAgreementsRepository;
    }

    /**
     * @param PaymentInformationManagementInterface $subject
     * @param int $cartId
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSavePaymentInformation(
        PaymentInformationManagementInterface $subject,
        $cartId,
        PaymentInterface $paymentMethod,
        AddressInterface $billingAddress = null
    ) {
    }
}
