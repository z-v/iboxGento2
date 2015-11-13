<?php

class MagicToolbox_MagicZoomPlus_Model_Observer {

    public function __construct() {

    }


    /*public function controller_action_predispatch($observer) {

    }*/

    /*public function beforeLoadLayout($observer) {

    }*/

    public function fixLayoutUpdates($observer) {
        //NOTE: to prevent an override of our templates with other modules

        //replace node to prevent dublicate
        //NOTE: SimpleXMLElement creates a node instead of empty values, so we use fake file name
        $child = new Varien_Simplexml_Element('<magiczoomplus module="MagicToolbox_MagicZoomPlus"><file>magictoolbox.xml</file></magiczoomplus>');
        Mage::app()->getConfig()->getNode('frontend/layout/updates')->extendChild($child, true);
        //add new node to the end
        $child = new Varien_Simplexml_Element('<magiczoomplus_layout_update module="MagicToolbox_MagicZoomPlus"><file>magiczoomplus.xml</file></magiczoomplus_layout_update>');
        Mage::app()->getConfig()->getNode('frontend/layout/updates')->appendChild($child);
    }

    /*public function controller_action_postdispatch($observer) {

    }*/


}

?>