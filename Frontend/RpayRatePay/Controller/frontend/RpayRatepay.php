<?php

/**
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, see <http://www.gnu.org/licenses/>.
 *
 * RpayRatepay
 *
 * @category   RatePAY
 * @copyright  Copyright (c) 2013 PayIntelligent GmbH (http://payintelligent.de)
 */
class Shopware_Controllers_Frontend_RpayRatepay extends Shopware_Controllers_Frontend_Payment
{

    /**
     * Stores an Instance of the Shopware\Models\Customer\Billing model
     *
     * @var Shopware\Models\Customer\Billing
     */
    private $_config;
    private $_user;
    private $_request;
    private $_modelFactory;
    private $_logging;
    private $_encryption;

    /**
     * Initiates the Object
     */
    public function init()
    {
        $this->_config = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config();
        $this->_user = Shopware()->Models()->find('Shopware\Models\Customer\Billing', Shopware()->Session()->sUserId);
        $this->_request = new Shopware_Plugins_Frontend_RpayRatePay_Component_Service_RequestService($this->_config->get('RatePaySandbox'));
        $this->_modelFactory = new Shopware_Plugins_Frontend_RpayRatePay_Component_Mapper_ModelFactory();
        $this->_logging = new Shopware_Plugins_Frontend_RpayRatePay_Component_Logging();
        $this->_encryption = new Shopware_Plugins_Frontend_RpayRatePay_Component_Encryption_ShopwareEncryption();
    }

    /**
     *  Checks the Paymentmethod
     */
    public function indexAction()
    {
        unset(Shopware()->Session()->RatePAY['errorMessage']);
        if (preg_match("/^rpayratepay(invoice|rate|debit|prepayment)$/", $this->getPaymentShortName())) {
            $this->_proceedPayment();
        } else {
            $this->_error('Die Zahlart ' . $this->getPaymentShortName() . ' wird nicht unterst&uuml;tzt!');
        }
    }

    /**
     * Updates phone, ustid, company and the birthday for the current user.
     */
    public function saveUserDataAction()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
        $requestParameter = $this->Request()->getParams();
        $user = Shopware()->Models()->find('Shopware\Models\Customer\Billing', $requestParameter['userid']);
        $debitUser = Shopware()->Models()->find('Shopware\Models\Customer\Debit', $requestParameter['userid']);
        $config = Shopware()->Plugins()->Frontend()->RpayRatePay()->Config();

        $return = 'OK';

        $updateData = array();
        if (!is_null($user)) {
            $updateData['phone'] = $requestParameter['ratepay_phone'] ? : $user->getPhone();
            $updateData['ustid'] = $requestParameter['ratepay_ustid'] ? : $user->getVatId();
            $updateData['company'] = $requestParameter['ratepay_company'] ? : $user->getCompany();
            $updateData['birthday'] = $requestParameter['ratepay_birthday'] ? : $user->getBirthday()->format("Y-m-d");
            try {
                Shopware()->Db()->update('s_user_billingaddress', $updateData, 'userID=' . $requestParameter['userid']);
                Shopware()->Log()->Info('Kundendaten aktualisiert.');
            } catch (Exception $exception) {
                Shopware()->Log()->Err('Fehler beim Updaten der Userdaten: ' . $exception->getMessage());
                $return = 'NOK';
            }
        }
        $updateData = array();
        if ($requestParameter['ratepay_debit_updatedebitdata']) {
            Shopware()->Session()->RatePAY['bankdata']['account'] = $requestParameter['ratepay_debit_accountnumber'];
            Shopware()->Session()->RatePAY['bankdata']['bankcode'] = $requestParameter['ratepay_debit_bankcode'];
            Shopware()->Session()->RatePAY['bankdata']['bankname'] = $requestParameter['ratepay_debit_bankname'];
            Shopware()->Session()->RatePAY['bankdata']['bankholder'] = $requestParameter['ratepay_debit_accountholder'];
            if ($config->get('RatePayBankData')) {
                $updateData = array(
                    'account' => $requestParameter['ratepay_debit_accountnumber'] ? : $debitUser->getAccount(),
                    'bankcode' => $requestParameter['ratepay_debit_bankcode'] ? : $debitUser->getBankCode(),
                    'bankname' => $requestParameter['ratepay_debit_bankname'] ? : $debitUser->getBankName(),
                    'bankholder' => $requestParameter['ratepay_debit_accountholder'] ? : $debitUser->getAccountHolder()
                );
                try {
                    $this->_encryption->saveBankdata($requestParameter['userid'], $updateData);
                    Shopware()->Log()->Info('Bankdaten aktualisiert.');
                } catch (Exception $exception) {
                    Shopware()->Log()->Err('Fehler beim Updaten der Bankdaten: ' . $exception->getMessage());
                    Shopware()->Log()->Debug($updateData);
                    $return = 'NOK';
                }
            }
        }
        echo $return;
    }

    /**
     * Procceds the whole Paymentprocess
     */
    private function _proceedPayment()
    {
        $paymentInitModel = $this->_modelFactory->getModel(new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentInit());
        $result = $this->_request->xmlRequest($paymentInitModel->toArray());
        if (Shopware_Plugins_Frontend_RpayRatePay_Component_Service_Util::validateResponse('PAYMENT_INIT', $result)) {
            Shopware()->Session()->RatePAY['transactionId'] = $result->getElementsByTagName('transaction-id')->item(0)->nodeValue;
            $paymentRequestModel = $this->_modelFactory->getModel(new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentRequest());
            $result = $this->_request->xmlRequest($paymentRequestModel->toArray());
            if (Shopware_Plugins_Frontend_RpayRatePay_Component_Service_Util::validateResponse('PAYMENT_REQUEST', $result)) {
                $orderNumber = $this->saveOrder(Shopware()->Session()->RatePAY['transactionId'], $this->createPaymentUniqueId(), 17);
                $paymentConfirmModel = $this->_modelFactory->getModel(new Shopware_Plugins_Frontend_RpayRatePay_Component_Model_PaymentConfirm());
                $matches = array();
                preg_match("/<descriptor.*>(.*)<\/descriptor>/", $this->_request->getLastResponse(), $matches);
                $dgNumber = $matches[1];
                $result = $this->_request->xmlRequest($paymentConfirmModel->toArray());
                if (Shopware_Plugins_Frontend_RpayRatePay_Component_Service_Util::validateResponse('PAYMENT_CONFIRM', $result)) {
                    if (Shopware()->Session()->sOrderVariables['sBasket']['sShippingcosts'] > 0) {
                        $this->initShipping($orderNumber);
                    }
                    try {
                        $orderId = Shopware()->Db()->fetchOne('SELECT `id` FROM `s_order` WHERE `ordernumber`=?', array($orderNumber));
                        Shopware()->Db()->update('s_order_attributes', array(
                            'attribute5' => $dgNumber,
                            'attribute6' => Shopware()->Session()->RatePAY['transactionId']
                                ), 'orderID=' . $orderId);
                    } catch (Exception $exception) {
                        Shopware()->Log()->Err($exception->getMessage());
                    }
                    $this->redirect(Shopware()->Front()->Router()->assemble(array(
                                'controller' => 'checkout',
                                'action' => 'finish'
                            ))
                    );
                } else {
                    $this->_error();
                }
            } else {
                $this->_error();
            }
        } else {
            $this->_error();
        }
    }

    /**
     * Redirects the User in case of an error
     */
    private function _error()
    {
        Shopware()->Session()->RatePAY['hidePayment'] = true;
        $this->View()->loadTemplate("frontend/RatePAYErrorpage.tpl");
    }

    /**
     * calcDesign-function for Ratenrechner
     */
    public function calcDesignAction()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
        $calcPath = realpath(dirname(__FILE__) . '/../../Views/frontend/Ratenrechner/php/');
        require_once $calcPath . '/PiRatepayRateCalc.php';
        require_once $calcPath . '/path.php';
        require_once $calcPath . '/PiRatepayRateCalcDesign.php';
    }

    /**
     * calcRequest-function for Ratenrechner
     */
    public function calcRequestAction()
    {
        Shopware()->Plugins()->Controller()->ViewRenderer()->setNoRender();
        $calcPath = realpath(dirname(__FILE__) . '/../../Views/frontend/Ratenrechner/php/');
        require_once $calcPath . '/PiRatepayRateCalc.php';
        require_once $calcPath . '/path.php';
        require_once $calcPath . '/PiRatepayRateCalcRequest.php';
    }

    /**
     * Initiates the Shipping-Position fo the given order
     *
     * @param string $orderNumber
     */
    private function initShipping($orderNumber)
    {
        try {
            $orderID = Shopware()->Db()->fetchOne("SELECT `id` FROM `s_order` WHERE `ordernumber`=?", array($orderNumber));
            Shopware()->Db()->query("INSERT INTO `rpay_ratepay_order_shipping` (`s_order_id`) VALUES(?)", array($orderID));
        } catch (Exception $exception) {
            Shopware()->Log()->Err($exception->getMessage());
        }
    }

}