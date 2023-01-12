<?php
/* Замените в строке ниже XX на числовой идентификатор платежной системы в HostCMS */
class Shop_Payment_System_HandlerXX extends Shop_Payment_System_Handler {

/* ЭТИ ПАРАМЕТРЫ НУЖНО НАСТРОИТЬ ДЛЯ ВАШЕГО САЙТА */
	
	// Идентификатор сайта в системе Paygine
	private $sector = 0;

	// Пароль для формирования цифровой подписи
	private $password = 'test';	

	// Режим работы платежной системы - тестовый (true) / рабочий (false)
	private $test_mode = true;

	// Процент комиссии платежной системы (сверх стоимости заказа)
	private $fee = 0;

/* КОНЕЦ БЛОКА НАСТРАИВАЕМЫХ ПАРАМЕТРОВ */

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
        $result_url = 'http://' . $site_alias . $shop_path . 'cart/';

		switch ($this->_shopOrder->shop_currency_id) {
			case 2:
				$currency = '978'; // EUR
				break;
			case 3:
				$currency = '840'; // USD
				break;
			default:
		    	$currency = '643'; // RUB
		    	break;
		}

		$price = $this->_shopOrder->getAmount();
		$amount_with_fee = round($price * (100.00 + $this->fee) / 100.00, 2);
		$signature  = base64_encode(md5($this->sector.($amount_with_fee*100).$currency.$this->password));

		$data = array(
			'sector' => $this->sector,
			'reference' => $order_id,
			'amount' => $amount_with_fee * 100,
			'description' => 'Оплата заказа №' . $order_id,
			'email' => htmlspecialchars($this->_shopOrder->email, ENT_QUOTES),
			'currency' => $currency,
			'mode' => 1,
			'url' => $result_url,
			'signature' => $signature
		);
		$options = array(
		    'http' => array(
		        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
		        'method'  => 'POST',
		        'content' => http_build_query($data),
		    ),
		);
		$context  = stream_context_create($options);
		$paygine_id = file_get_contents($this->getURL('/webapi/Register'), false, $context);

		if(intval($paygine_id)==0)
			return false;

        ob_start();

        ?>

<h1>Оплата по банковской карте Visa/MasterCard</h1>
<p>Сумма к оплате составляет <strong><?php echo $amount_with_fee ?> <?php echo Core_Entity::factory('Shop_Currency')->find($this->_shopOrder->shop_currency_id)->name?></strong>
<?php if ($this->fee >= 0.01) { ?>
, в т.ч. комиссия <?php echo $this->fee ?>%, <strong><?php echo round($amount_with_fee - $this->_shopOrder->getAmount(), 2) ?> <?php echo Core_Entity::factory('Shop_Currency')->find($this->_shopOrder->shop_currency_id)->name?></strong>
<?php } ?>
</p>
<form accept-charset='utf8' action='<?php echo $this->getURL("/webapi/Purchase")?>' method='post'>
<input type='hidden' name='sector' value='<?php echo $this->sector?>'>
<input type='hidden' name='id' value='<?php echo $paygine_id?>'>
<input type='hidden' name='signature' value='<?php echo base64_encode(md5($this->sector.$paygine_id.$this->password))?>'>
<p><img src="//www.paygine.ru/assets/mytemplate/img/logo.png" width="122" height="31" alt="Paygine" title="Платежная система Paygine" style="float:left; margin-right:0.5em; margin-bottom:0.5em; padding-top:0.25em;">
	Оплата по банковской карте осуществляется через платежную систему <b><a href="http://www.paygine.ru">Paygine</a></b>, обеспечивающую простой и безопасный способ оплаты по карте.
<?php if ($this->fee >= 0.01) { ?>
Комиссия за проведение платежа <?php echo $this->fee ?>%.
<?php } ?>
</p>
<input type='submit' value='Оплатить'>
</form>

        <?php

        return ob_get_clean();
    }

    public function paymentProcessing() {
    	$order_id = $this->_shopOrder->id;
        $oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
        $site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
        $shop_path = $this->_shopOrder->Shop->Structure->getPath();
        $result_url = 'http://' . $site_alias . $shop_path . 'cart/';
        $success_url = $result_url . '?order_id=' . $order_id . '&payment=success';
        $fail_url = $result_url . '?order_id=' . $order_id . "&payment=fail";

		try {
			$signature = base64_encode(md5($this->sector . $_REQUEST['id'] . $_REQUEST['operation'] . $this->password));
			$context  = stream_context_create(array(
				'http' => array(
					'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
					'method'  => 'POST',
					'content' => http_build_query(array(
						'sector' => $this->sector,
						'id' => $_REQUEST['id'],
						'operation' => $_REQUEST['operation'],
						'signature' => $signature
					)),
				)
			));

			$repeat = 3;

			while ($repeat) {
				$repeat--;
				sleep(1);
	
				$xml = file_get_contents($this->getURL('/webapi/Operation'), false, $context);
				if (!$xml)
					throw new Exception("Empty data");
				$xml = simplexml_load_string($xml);
				if (!$xml)
					throw new Exception("Non valid XML was received");
				$response = json_decode(json_encode($xml));
				if (!$response)
					throw new Exception("Non valid XML was received");

				$tmp_response = (array)$response;
				unset($tmp_response["signature"]);
				$signature = base64_encode(md5(implode('', $tmp_response) . $this->password));
				if ($signature !== $response->signature)
					throw new Exception("Invalid signature");

				// check order state
				if(($response->type != 'PURCHASE' && $response->type != 'EPAYMENT') || $response->state != 'APPROVED')
					continue;

				$amount = intval($response->amount);
				$amount_with_fee = round($this->_shopOrder->getAmount() * (100.00 + $this->fee) / 100.00, 2);
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
}
