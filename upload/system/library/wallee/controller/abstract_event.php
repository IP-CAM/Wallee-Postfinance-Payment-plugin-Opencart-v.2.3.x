<?php

namespace Wallee\Controller;

abstract class AbstractEvent extends AbstractController {

	protected function validate(){
		$this->language->load('extension/payment/wallee');
		$this->validatePermission();
		// skip valdiating order.
	}
}