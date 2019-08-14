<?php
/**
 * @category   Flowmailer
 * @package    Flowmailer_Fmconnector
 * @author     Casper Mout <casper@flowmailer.com>
 * @author     Richard van Looijen <richard@flowmailer.com>
 * @copyright  Copyright (c) 2016 Flowmailer (http://flowmailer.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
require_once(Mage::getBaseDir('lib') . '/Flowmailer/FlowmailerAPI.class.php');

if (Mage::helper('core')->isModuleEnabled('Aschroder_SMTPPro') && class_exists('Aschroder_SMTPPro_Model_Email_Template')) {
    class Flowmailer_Fmconnector_Model_Email_Template_Wrapper extends Aschroder_SMTPPro_Model_Email_Template {}
} else {
    class Flowmailer_Fmconnector_Model_Email_Template_Wrapper extends Mage_Core_Model_Email_Template {}
}
 
class Flowmailer_Fmconnector_Model_Email_Template extends Flowmailer_Fmconnector_Model_Email_Template_Wrapper
{

	function mage_to_array($object, $maxdepth) {
	
		if ($maxdepth <= 0) { 
			return "maxdepth";
		}
	        $public = [];
	        if(is_array($object)) {
			foreach ($object as $key => $value) {
				$public[$key] = $this->mage_to_array($value, $maxdepth - 1);
			}
	
	        } else if (is_object($object) && (is_subclass_of($object, "Varien_Object") || get_class($object) == "Varien_Object")) {
	            $public = array_replace($public, $object->getData());
	
		} else if(is_object($object)) {
		
		        $reflection = new ReflectionClass(get_class($object));
			
		        foreach ($reflection->getMethods(ReflectionProperty::IS_PUBLIC) as $method) {
				if(strpos($method->getName(), 'get') !== 0) {
					continue;
				}
				if(count($method->getParameters()) > 1) {
					continue;
				}
				try {
				$value = $method->invoke($object);
				$value = $this->mage_to_array($value, $maxdepth - 1);
				$name = $method->getName();
				$public[$name] = $value;
				} catch(Exception $e) {}
			}
	
		} else {
			return $object;
		}
	
	        return $public;
	    }

    public function sendTransactional($templateId, $sender, $email, $name, $vars=array(), $storeId=null)
    {
        $this->setSentSuccess(false);

        if (is_numeric($templateId)) {
		parent::sendTransactional($templateId, $sender, $email, $name, $vars, $storeId);
 	       return $this;

        } else if (!is_string($templateId) || strpos($templateId, 'fm_') !== 0) {
		parent::sendTransactional($templateId, $sender, $email, $name, $vars, $storeId);
    		return $this;

        } else {
            $this->loadDefault($templateId, 'en_US');
        }

        if (($storeId === null) && $this->getDesignConfig()->getStore()) {
            $storeId = $this->getDesignConfig()->getStore();
        }
        if (!isset($vars['store'])) {
            $vars['store'] = Mage::app()->getStore($storeId);
        }
	$store = $vars['store'];


        $emails = array_values((array)$email);
        $names = is_array($name) ? $name : (array)$name;
        $names = array_values($names);
        foreach ($emails as $key => $email) {
            if (!isset($names[$key])) {
                $names[$key] = substr($email, 0, strpos($email, '@'));
            }
        }

        $vars['email'] = reset($emails);
        $vars['name'] = reset($names);

        $text = $this->getProcessedTemplate($vars, true);

	$vardata = $this->mage_to_array($vars, 6);
	$vardata['text'] = $text;
        $vardata['template_id'] = $templateId;
	if(isset($vars['order'])) {

		$order = $vars['order'];
		$items = $order->getAllVisibleItems();
		$itemdatas = [];
		foreach($items as $item) {
			$d = $item->getData();
			$d['product_options'] = $item->getProductOptions();
			$itemdatas[] = $d;
		}

		$vardata['order']['items'] = $itemdatas;
		$vardata['shipping'] = $vars['order']->getShippingAddress()->getData();
	}

        $vardata['store']['url'] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
        $vardata['store']['frontName'] = Mage::app()->getStore()->getFrontEndName();

#        Mage::log(print_r($vardata, true), null, 'mail.log');

        if (!is_array($sender)) {
            $senderName = Mage::getStoreConfig('trans_email/ident_' . $sender . '/name', $storeId);
            $senderAddress = Mage::getStoreConfig('trans_email/ident_' . $sender . '/email', $storeId);
        } else {
            $senderName = $sender['name'];
            $senderAddress = $sender['email'];
        }

        $accountId = Mage::getStoreConfig('fmconnector/general/api_account_id', $storeId);
        $clientId = Mage::getStoreConfig('fmconnector/general/api_client_id', $storeId);
        $clientSecret = Mage::getStoreConfig('fmconnector/general/api_client_secret', $storeId);

        $api = new \flowmailer\FlowmailerAPI($accountId, $clientId, $clientSecret);

        foreach ($emails as $key => $email) {
            Mage::log('flowmailer submit ' . $templateId . ' to ' . $email, null, 'mail.log');

 	    $submitMessage = new \flowmailer\SubmitMessage();
	    $submitMessage->messageType = 'EMAIL';
	    $submitMessage->subject = $templateId;
            $submitMessage->data = $vardata;

	    $submitMessage->senderAddress = $senderAddress;
	    $submitMessage->headerFromAddress = $senderAddress;
	    $submitMessage->headerFromName = $senderName;

	    $submitMessage->recipientAddress = $email;
	    $submitMessage->headerToAddress = $email;
	    $submitMessage->headerToName = $names[$key];

            $submitMessage->headers = array(
			array(
				'name' => 'X-Store-Id',
				'value' => $storeId,
			),
			array(
				'name' => 'X-Group-Id',
				'value' => $store->getGroupId(),
			),
			array(
				'name' => 'X-Website-Id',
				'value' => $store->getWebsiteId(),
			),
			array(
				'name' => 'X-Template-Id',
				'value' => $templateId,
			),
		);

            $api->submitMessage($submitMessage);
        }

        $this->setSentSuccess(true);
        return $this;
    }
}
