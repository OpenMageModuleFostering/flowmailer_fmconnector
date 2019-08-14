<?php
/**
 * @category   Flowmailer
 * @package    Flowmailer_Fmconnector
 * @author     Casper Mout <casper@flowmailer.com>
 * @author     Richard van Looijen <richard@flowmailer.com>
 * @copyright  Copyright (c) 2016 Flowmailer (http://flowmailer.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Flowmailer_Fmconnector_Model_System_Config_Source_Email_Template extends Mage_Adminhtml_Model_System_Config_Source_Email_Template
{
    /**
     * Config xpath to email template node
     *
     */
    const XML_PATH_TEMPLATE_EMAIL = 'global/template/email/';

    /**
     * Generate list of email templates
     *
     * @return array
     */
    public function toOptionArray()
    {
        if(!$collection = Mage::registry('config_system_email_template')) {
            $collection = Mage::getResourceModel('core/email_template_collection')
                ->load();

            Mage::register('config_system_email_template', $collection);
        }
        $options = $collection->toOptionArray();

        $templateName = Mage::helper('adminhtml')->__('Connect to Flowmailer');
        $nodeName = 'fm_'.str_replace('/', '_', $this->getPath());
        $templateLabelNode = Mage::app()->getConfig()->getNode(self::XML_PATH_TEMPLATE_EMAIL . $nodeName . '/label');
        if ($templateLabelNode) {
//            $templateName = Mage::helper('adminhtml')->__((string)$templateLabelNode);
//            $templateName = Mage::helper('adminhtml')->__('%s (Connect to Flowmailer)', $templateName);
            array_unshift(
                $options,
                array(
                    'value'=> $nodeName,
                    'label' => $templateName
                )
            );
        }

        $templateName = Mage::helper('adminhtml')->__('Default Template from Locale');
        $nodeName = str_replace('/', '_', $this->getPath());
        $templateLabelNode = Mage::app()->getConfig()->getNode(self::XML_PATH_TEMPLATE_EMAIL . $nodeName . '/label');
        if ($templateLabelNode) {
            $templateName = Mage::helper('adminhtml')->__((string)$templateLabelNode);
            $templateName = Mage::helper('adminhtml')->__('%s (Default Template from Locale)', $templateName);
        }
        array_unshift(
            $options,
            array(
                'value'=> $nodeName,
                'label' => $templateName
            )
        );

        return $options;
    }
}
