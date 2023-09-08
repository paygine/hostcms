<?php

$tmpDir = Market_Controller::instance()->tmpDir . DIRECTORY_SEPARATOR;

$aOptions = Market_Controller::instance()->options;

$aReplace = array();

$version = '1.0.1';
$date = '2022-12-13';
$sLng = 'ru';
$aPossibleLanguages = array('en', 'ru');
$sSitePostfix = '';

foreach ($aOptions as $optionName => $optionValue)
{
	$aReplace['%' . $optionName . '%'] = $optionValue;
}

$Install_Controller = Install_Controller::instance();
$Install_Controller->setTemplatePath($tmpDir);

$oShop = Core_Entity::factory('Shop', $aOptions['shop_id']);

$aPaymentsystemi18n = array(
	'ru' => array(
		17 => array('file' => 'handler17.php', 'name' => 'Paygine', 'description' => 'Плагин «Paygine» — платежное решение для сайтов на HostCMS:
3 встроенных способа приема платежей. Для юридических лиц и ИП. Быстрое поступление денег на счет компании. Полное соответствие 54-ФЗ. Бесплатное подключение.'),
	)
);

// Платежные системы
foreach ($aPaymentsystemi18n[$sLng] as $iPaymentsystemId => $aPaymentsystem)
{
	$oShop_Payment_System = Core_Entity::factory('Shop_Payment_System');
	$oShop_Payment_System->name = $aPaymentsystem['name'];
	$oShop_Payment_System->description = $aPaymentsystem['description'];
	$oShop->add($oShop_Payment_System);

	$aReplace['Shop_Payment_System_Handler' . $iPaymentsystemId] = 'Shop_Payment_System_Handler' . $oShop_Payment_System->id;

	$sContent = $Install_Controller->loadFile($tmpDir . "tmp/" . $aPaymentsystem['file'], $aReplace);
	$oShop_Payment_System->savePaymentSystemFile($sContent);
}
