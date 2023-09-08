<?php
class Shop_Payment_System_Handler17 extends Shop_Payment_System_Handler {
	/* ЭТИ ПАРАМЕТРЫ НУЖНО НАСТРОИТЬ ДЛЯ ВАШЕГО САЙТА */
	// Идентификатор сайта в системе Paygine
	private $sector = 0;

	// Пароль для формирования цифровой подписи
	private $password = 'test';

	// Режим работы платежной системы - тестовый (true) / рабочий (false)
	private $test_mode = true;

	// Процент комиссии платежной системы (сверх стоимости заказа)
	private $fee = 0;

	// Варианты оплаты:
	// 1 - Стандартный эквайринг (одностадийка) - /webapi/Purchase
	// 2 - Стандартный эквайринг (двустадийка) - /webapi/Authorize
	// 3 - Халва частями (одностадийка) - /webapi/custom/svkb/PurchaseWithInstallment
	// 4 - Халва частями (двустадийка) - /webapi/custom/svkb/AuthorizeWithInstallment
	// 5 - СБП - /webapi/PurchaseSBP
	private $paymentType = 1;

	// Код ставки НДС для ККТ:
	// 1 – ставка НДС 20%
	// 2 – ставка НДС 10%
	// 3 – ставка НДС расч. 20/120
	// 4 – ставка НДС расч. 10/110
	// 5 – ставка НДС 0%
	// 6 – НДС не облагается"
	private $tax = 6;
	/* КОНЕЦ БЛОКА НАСТРАИВАЕМЫХ ПАРАМЕТРОВ */

	public function centify($value) {
		return intval(strval($value * 100));
	}

	public function getFiscalPositionsShopCart($tax) {
		$fiscal_positions = '';
		$fiscal_amount = 0;
		$shop_cart = [];
		$sc_key = 0;

		$aShopOrderItems = $this->_shopOrder->Shop_Order_Items->findAll(false);
		$shopDeliveryID = Core_Array::get($this->_orderParams, 'shop_delivery_condition_id', 0, 'int');
		$shippingCost = Core_Entity::factory('Shop_Delivery_Condition', $shopDeliveryID)->price;

		foreach ($aShopOrderItems as $oShop_Cart) {
			if($oShop_Cart->type != 0) {
				continue;
			}

			$element_name = $oShop_Cart->name;
			$element_quantity = intval($oShop_Cart->quantity);
			$element_price = intval(round($oShop_Cart->price * 100));

			if(strpos($element_name, ';') !== false) {
				$element_name = str_replace(';', '', $element_name);
			}

			$fiscal_positions .= $element_quantity . ';';
			$fiscal_positions .= $element_price . ';';
			$fiscal_positions .= $tax . ';';
			$fiscal_positions .= $element_name . '|';
			$fiscal_amount += $element_quantity * $element_price;

			$shop_cart[$sc_key]['name'] = $element_name;
			$shop_cart[$sc_key]['quantityGoods'] = $element_quantity;
			$shop_cart[$sc_key]['goodCost'] = round($oShop_Cart->price * $shop_cart[$sc_key]['quantityGoods'], 2);

			$sc_key++;
		}

		if($shippingCost > 0) {
			$fiscal_positions .= '1;';
			$delivery_price = intval(round($shippingCost * 100));
			$fiscal_positions .= $delivery_price . ';';
			$fiscal_positions .= $tax . ';';
			$fiscal_positions .= 'Доставка' . '|';
			$fiscal_amount += $delivery_price;

			$shop_cart[$sc_key]['quantityGoods'] = 1;
			$shop_cart[$sc_key]['goodCost'] = round($shippingCost, 2);
			$shop_cart[$sc_key]['name'] = 'Доставка';
		}

		$order_amount = intval($this->_shopOrder->getAmount() * 100);
		$fiscal_diff = abs($fiscal_amount - $order_amount);

		if ($fiscal_diff) {
			$fiscal_positions .= '1;' . $fiscal_diff . ';6;Скидка;14|';
			$shop_cart = [];
		}
		$fiscal_positions = substr($fiscal_positions, 0, -1);

		return [$fiscal_positions, $shop_cart];
	}

	private function getURL($path) {
		if ($this->test_mode) {
			return 'https://test.paygine.com' . $path;
		} else {
			return 'https://pay.paygine.com' . $path;
		}
	}

	public function execute() {
		parent::execute();
		$this->printNotification();
		return $this;
	}

	protected function _processOrder() {
		parent::_processOrder();
		$this->setXSLs();
		$this->send();
		return $this;
	}

	public function getInvoice() {
		return $this->getNotification();
	}

	public function getNotification() {
		$order_id = $this->_shopOrder->id;
		$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
		$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
		$shop_path = $this->_shopOrder->Shop->Structure->getPath();
		$result_url = ($this->_shopOrder->Shop->Structure->https ? 'https://' : 'http://') . $site_alias . $shop_path . 'cart/';

		switch (strtoupper($this->_shopOrder->Shop_Currency->code)) {
			case 'EUR':
				$currency = '978';
				break;
			case 'USD':
				$currency = '840';
				break;
			default: // RUB
				$currency = '643';
				break;
		}

		switch($this->paymentType) {
			case '2':
				$payment_path = '/webapi/Authorize';
				break;
			case '3':
				$payment_path = '/webapi/custom/svkb/PurchaseWithInstallment';
				break;
			case '4':
				$payment_path = '/webapi/custom/svkb/AuthorizeWithInstallment';
				break;
			case '5':
				$payment_path = '/webapi/PurchaseSBP';
				break;
			default:
				$payment_path = '/webapi/Purchase';
		}

		$price = $this->_shopOrder->getAmount();
		$amount_with_fee = round($price * (100.0 + $this->fee) / 100.0, 2);
		$order_amount = intval($amount_with_fee * 100);
		$signature  = base64_encode(md5($this->sector. $order_amount .$currency.$this->password));
		list($fiscal_positions, $shop_cart) = $this->getFiscalPositionsShopCart($this->tax);

		$data = [
			'sector' => $this->sector,
			'reference' => $order_id,
			'fiscal_positions' => $fiscal_positions,
			'amount' => $order_amount,
			'description' => 'Оплата заказа №' . $order_id,
			'email' => htmlspecialchars($this->_shopOrder->email, ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $result_url,
			'signature' => $signature
		];

		$paygine_id = $this->sendRequest($this->getURL('/webapi/Register'), $data);

		if(intval($paygine_id)==0)
			return false;

		ob_start();

		$shop_cart_encoded = '';
		if($shop_cart && ($this->paymentType == 3 || $this->paymentType == 4)) {
			$shop_cart_encoded = base64_encode(json_encode($shop_cart, JSON_UNESCAPED_UNICODE));
		}?>

		<h1>Оплата по банковской карте Visa/MasterCard</h1>
		<div style="margin-top:40px;">
			<form accept-charset='utf8' action='<?php echo $this->getURL($payment_path)?>' method='post'>
				<input type='hidden' name='sector' value='<?php echo $this->sector?>'>
				<input type='hidden' name='id' value='<?php echo $paygine_id?>'>

				<?if($this->paymentType == 3 || $this->paymentType == 4):?>
					<input type='hidden' name='shop_cart' value='<?php echo $shop_cart_encoded?>'>
				<?endif?>

				<input type='hidden' name='signature' value='<?php echo base64_encode(md5($this->sector.$paygine_id.$shop_cart_encoded.$this->password))?>'>
				<div style="display:flex;flex-direction:column;">
					<img style="display:block;margin:0 auto 20px;width:150px;height:50px;" src="//paygine.ru/local/templates/paygine_main/img/logo-blue.svg" alt="Paygine" title="Платежная система Paygine">
					<p>Оплата по банковской карте осуществляется через платежную систему <b><a target="_blank" href="https://www.paygine.ru">Paygine</a></b>, обеспечивающую простой и безопасный способ оплаты по карте.
						<?php if ($this->fee >= 0.01) { ?>
							Комиссия за проведение платежа <?php echo $this->fee ?>%.
						<?php } ?>
					</p>
					<p>Сумма к оплате составляет: <strong><?php echo $amount_with_fee ?> <?php echo $this->_shopOrder->Shop_Currency->name?></strong>
						<?php if ($this->fee >= 0.01) { ?>
							, в т.ч. комиссия <?php echo $this->fee ?>%, <strong><?php echo round($amount_with_fee - $this->_shopOrder->getAmount(), 2) ?> <?php echo $this->_shopOrder->Shop_Currency->name?></strong>
						<?php } ?>
					</p>
				</div>
				<input style="border:none;font-size:13px;background:linear-gradient(180deg, #00DBE5 -0.55%, #8E67FF 163.03%);color:#fff;text-align:center;border-radius:6px;display:block;margin:10px auto 0;padding:5px 14px" type='submit' value='Оплатить'>
			</form>
		</div>

		<?php return ob_get_clean();
	}

	public function checkPaymentBeforeContent() {
		if (isset($_REQUEST['reference'])) {
			// Получаем ID заказа (cms)
			$order_id = intval(Core_Array::getRequest('reference'));
			$oShop_Order = Core_Entity::factory('Shop_Order')->find($order_id);
			if (!is_null($oShop_Order->id)) {
				// Вызов обработчика платежной системы
				Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
					->shopOrder($oShop_Order)
					->paymentProcessing();
			}
			exit();
		}
	}

	public function paymentProcessing() {
		$this->ProcessResult();
		return TRUE;
	}

	public function ProcessResult() {
		$paygine_id = isset($_REQUEST['id']) ? $_REQUEST['id'] : null;
		$paygine_operation = isset($_REQUEST['operation']) ? $_REQUEST['operation'] : null;
		$sector_id = (int) $this->sector;
		$order_id = $this->_shopOrder->id;
		$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
		$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
		$shop_path = $this->_shopOrder->Shop->Structure->getPath();
		$result_url = ($this->_shopOrder->Shop->Structure->https ? 'https://' : 'http://') . $site_alias . $shop_path . 'cart/';
		$success_url = $result_url . '?order_id=' . $order_id . '&payment=success';
		$fail_url = $result_url . '?order_id=' . $order_id . "&payment=fail";

		try {
			$signature = base64_encode(md5($sector_id . $paygine_id . $paygine_operation . $this->password));
			$data = [
				'sector' => $sector_id,
				'id' => $paygine_id,
				'operation' => $paygine_operation,
				'signature' => $signature
			];

			$repeat = 3;

			while ($repeat) {
				$repeat--;
				sleep(1);

				$xml = $this->sendRequest($this->getURL('/webapi/Operation'), $data);
				if (!$xml)
					throw new Exception("Empty data");
				$xml = simplexml_load_string($xml);
				if (!$xml)
					throw new Exception("Non valid XML was received");
				$response = json_decode(json_encode($xml));
				if (!$response)
					throw new Exception("Non valid XML was received");

				$tmp_response = (array)$response;
				unset($tmp_response["signature"], $tmp_response["ofd_state"]);
				$signature = base64_encode(md5(implode('', $tmp_response) . $this->password));
				if ($signature !== $response->signature)
					throw new Exception("Invalid signature");

				// check order state
				if(($response->type != 'PURCHASE' && $response->type != 'PURCHASE_BY_QR' && $response->type != 'AUTHORIZE') || $response->state != 'APPROVED')
					continue;

				$amount = $response->buyIdSumAmount ? $response->buyIdSumAmount : intval($response->amount);
				$amount_with_fee = round($this->_shopOrder->getAmount() * (100.0 + $this->fee) / 100.0, 2);
				if ($amount != intval($amount_with_fee * 100) || $amount <= 0)
					throw new Exception("Invalid price");

				$this->_shopOrder->paid();
				$this->setXSLs();
				$this->send();

				header("Location: {$success_url}", true, 302);
				die();
			}

			header("Location: {$fail_url}", true, 302);
			die();

		} catch (Exception $ex) {
			error_log($ex->getMessage());
			header("Location: {$fail_url}", true, 302);
			die();
		}
	}

	/**
	 * @param $url string
	 * @param $data array
	 * @param string $method string
	 * @return false|string
	 */
	public static function sendRequest($url, $data, $method = 'POST') {
		$query = http_build_query($data);
		$context = stream_context_create([
			'http' => [
				'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
					. "Content-Length: " . strlen($query) . "\r\n",
				'method'  => $method,
				'content' => $query
			]
		]);
		if (!$context)
			return false;

		return file_get_contents($url, false, $context);
	}
}