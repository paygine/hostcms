// ------------------------------------------------
// Подготовка редиректа для Paygine
// ------------------------------------------------
if (isset($_REQUEST['id']) && isset($_REQUEST['operation']) && isset($_REQUEST['reference'])) {
	$oShop_Order = Core_Entity::factory('Shop_Order')->find($_REQUEST['reference']);
	if (!is_null($oShop_Order->id))	{
		// Вызов обработчика платежной системы
		Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
			->shopOrder($oShop_Order)
			->paymentProcessing();
	}
}

