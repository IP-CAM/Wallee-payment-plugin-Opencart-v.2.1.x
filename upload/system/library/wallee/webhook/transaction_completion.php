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

namespace Wallee\Webhook;

/**
 * Webhook processor to handle transaction completion state transitions.
 */
class TransactionCompletion extends AbstractOrderRelated {

	/**
	 *
	 * @see AbstractOrderRelated::loadEntity()
	 * @return \Wallee\Sdk\Model\TransactionCompletion
	 */
	protected function loadEntity(Request $request){
		$completion_service = new \Wallee\Sdk\Service\TransactionCompletionService(\WalleeHelper::instance($this->registry)->getApiClient());
		return $completion_service->read($request->getSpaceId(), $request->getEntityId());
	}

	protected function getOrderId($completion){
		/* @var \Wallee\Sdk\Model\TransactionCompletion $completion */
		return $completion->getLineItemVersion()->getTransaction()->getMerchantReference();
	}
	
	protected function getTransactionId($entity){
		/* @var $entity \Wallee\Sdk\Model\TransactionCompletion */
		return $entity->getLinkedTransaction();
	}
	
	protected function processOrderRelatedInner(array $order_info, $completion){
		/* @var \Wallee\Sdk\Model\TransactionCompletion $completion */
		switch ($completion->getState()) {
			case \Wallee\Sdk\Model\TransactionCompletionState::FAILED:
				$this->failed($completion, $order_info);
				break;
			case \Wallee\Sdk\Model\TransactionCompletionState::SUCCESSFUL:
				$this->success($completion, $order_info);
				break;
			default:
				// Ignore PENDING & CREATE
				// Nothing to do.
				break;
		}
	}

	protected function success(\Wallee\Sdk\Model\TransactionCompletion $completion, array $order_info){
		$completion_job = \Wallee\Entity\CompletionJob::loadByJob($this->registry, $completion->getLinkedSpaceId(), $completion->getId());
		if (!$completion_job->getId()) {
			//We have no completion job with this id -> the server could not store the id of the completion after sending the request. (e.g. connection issue or crash)
			//We only have on running completion which was not yet processed successfully and use it as it should be the one the webhook is for.
			$completion_job = \Wallee\Entity\CompletionJob::loadRunningForOrder($this->registry, $order_info['order_id']);
			if (!$completion_job->getId()) {
				//completion not initated in shop backend ignore
				return;
			}
			$completion_job->setJobId($completion->getId());
		}
		$completion_job->setAmount($completion->getPaymentInformation());
		$completion_job->setState(\Wallee\Entity\CompletionJob::STATE_SUCCESS);
		
		$completion_job->save();
	}

	protected function failed(\Wallee\Sdk\Model\TransactionCompletion $completion, array $order_info){
		$completion_job = \Wallee\Entity\CompletionJob::loadByJob($this->registry, $completion->getLinkedSpaceId(), $completion->getId());
		if (!$completion_job->getId()) {
			//We have no completion job with this id -> the server could not store the id of the completion after sending the request. (e.g. connection issue or crash)
			//We only have on running completion which was not yet processed successfully and use it as it should be the one the webhook is for.
			$completion_job = \Wallee\Entity\CompletionJob::loadRunningForOrder($this->registry, $order_info['order_id']);
			if (!$completion_job->getId()) {
				return;
			}
			$completion_job->setJobId($completion->getId());
		}
		if ($completion->getFailureReason() != null) {
			$completion_job->setFailureReason($completion->getFailureReason()->getDescription());
		}
		
		$completion_job->setAmount($completion->getPaymentInformation());
		$completion_job->setState(\Wallee\Entity\CompletionJob::STATE_FAILED_CHECK);
		\Wallee\Entity\Alert::loadFailedJobs($this->registry)->modifyCount(1);
		
		$completion_job->save();
	}
}