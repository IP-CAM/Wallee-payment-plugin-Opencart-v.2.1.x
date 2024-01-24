<?php
/**
 * Wallee OpenCart
 *
 * This OpenCart module enables to process payments with Wallee (https://www.wallee.com).
 *
 * @package Whitelabelshortcut\Wallee
 * @author wallee AG (https://www.wallee.com)
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache Software License (ASL 2.0)
 */
require_once modification(DIR_SYSTEM . 'library/wallee/helper.php');

class ControllerExtensionWalleeCompletion extends \Wallee\Controller\AbstractController {

	public function index(){
		$this->response->addHeader('Content-Type: application/json');
		try {
			$this->validate();
			
			$completion_job = \Wallee\Entity\CompletionJob::loadRunningForOrder($this->registry, $this->request->get['order_id']);
			
			if ($completion_job->getId() !== null) {
				throw new Exception($this->language->get('error_already_running'));
			}
			
			$transaction_info = \Wallee\Entity\TransactionInfo::loadByOrderId($this->registry, $this->request->get['order_id']);
			
			if (!\WalleeHelper::instance($this->registry)->isCompletionPossible($transaction_info)) {
				throw new \Exception($this->language->get('error_cannot_create_job'));
			}
			
			// ensure line items are current (e.g. events were skipped when order is edited)
			\Wallee\Service\Transaction::instance($this->registry)->updateLineItemsFromOrder($this->request->get['order_id']);
			
			$job = \Wallee\Service\Completion::instance($this->registry)->create($transaction_info);
			\Wallee\Service\Completion::instance($this->registry)->send($job);
			
			$this->load->model('extension/wallee/order');
			$new_buttons = $this->model_extension_wallee_order->getButtons($this->request->get['order_id']);
			
			$this->response->setOutput(
					json_encode(
							array(
								'success' => sprintf($this->language->get('message_completion_success'), $transaction_info->getTransactionId()),
								'buttons' => $new_buttons 
							)));
		}
		catch (Exception $e) {
			$this->response->setOutput(json_encode(array(
				'error' => $e->getMessage() 
			)));
		}
	}

	protected function getRequiredPermission(){
		return 'extension/wallee/completion';
	}
}