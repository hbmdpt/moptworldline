<?php

namespace MoptWordline\Service;

use Monolog\Logger;
use MoptWordline\Bootstrap\Form;
use OnlinePayments\Sdk\DataObject;
use OnlinePayments\Sdk\Domain\CaptureResponse;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutResponse;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Kernel;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use MoptWordline\Adapter\WordlineSDKAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;

class PaymentHandler
{
    private WordlineSDKAdapter $adapter;
    private OrderTransactionEntity $orderTransaction;
    private TranslatorInterface $translator;
    private EntityRepositoryInterface $orderRepository;
    private EntityRepositoryInterface $orderTransactionRepository;
    private Context $context;
    private OrderTransactionStateHandler $transactionStateHandler;

    /**
     * @param SystemConfigService $systemConfigService
     * @param Logger $logger
     * @param OrderTransactionEntity $orderTransaction
     * @param TranslatorInterface $translator
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param Context $context
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function __construct(
        SystemConfigService          $systemConfigService,
        Logger                       $logger,
        OrderTransactionEntity       $orderTransaction,
        TranslatorInterface          $translator,
        EntityRepositoryInterface    $orderRepository,
        EntityRepositoryInterface    $orderTransactionRepository,
        Context                      $context,
        OrderTransactionStateHandler $transactionStateHandler
    )
    {
        $salesChannelId = $orderTransaction->getOrder()->getSalesChannelId();
        $this->adapter = new WordlineSDKAdapter($systemConfigService, $logger, $salesChannelId);
        $this->orderTransaction = $orderTransaction;
        $this->translator = $translator;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->context = $context;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    /**
     * @param string $hostedCheckoutId
     * @return int
     * @throws \Exception
     */
    public function updatePaymentStatus(string $hostedCheckoutId): int
    {
        $status = $this->updatePaymentTransactionStatus($hostedCheckoutId);
        $this->updateOrderTransactionState($status, $hostedCheckoutId);

        return $status;
    }

    /**
     * @return CreateHostedCheckoutResponse
     * @throws \Exception
     */
    public function createPayment(): CreateHostedCheckoutResponse
    {
        $order = $this->orderTransaction->getOrder();
        $amountTotal = $order->getAmountTotal();
        $currencyISO = $this->getCurrencyISO();

        $this->log($this->translator->trans('buildingOrder'));
        $hostedCheckoutResponse = $this->adapter->createPayment($amountTotal, $currencyISO);
        $this->saveOrderCustomFields(0, $hostedCheckoutResponse->getHostedCheckoutId());
        return $hostedCheckoutResponse;
    }

    /**
     * @param string $hostedCheckoutId
     * @return DataObject|null
     * @throws \Exception
     */
    public function capturePayment(string $hostedCheckoutId): ?CaptureResponse
    {
        $status = $this->updatePaymentTransactionStatus($hostedCheckoutId);

        if (!in_array($status, Payment::STATUS_PENDING_CAPTURE)) {
            $this->log('operationIsNotPossibleDueToCurrentStatus' . $status, Logger::ERROR);
            return null;
        }

        $amount = $this->orderTransaction->getOrder()->getAmountTotal();
        $captureResponse = $this->adapter->capturePayment($hostedCheckoutId, $amount);
        $this->log('capturePayment', 0, $captureResponse->toJson());
        $newStatus = $captureResponse->getStatusOutput()->getStatusCode();
        $this->saveOrderCustomFields($newStatus, $hostedCheckoutId);

        $this->updateOrderTransactionState($newStatus, $hostedCheckoutId);

        return $captureResponse;
    }

    /**
     * @param string $hostedCheckoutId
     * @return bool
     * @throws \Exception
     */
    public function cancelPayment(string $hostedCheckoutId): bool
    {
        $status = $this->updatePaymentTransactionStatus($hostedCheckoutId);
        if (!in_array($status, Payment::STATUS_PENDING_CAPTURE)) {
            $this->log('operationIsNotPossibleDueToCurrentStatus' . $status, Logger::ERROR);
            return false;
        }

        $cancelResponse = $this->adapter->cancelPayment($hostedCheckoutId);
        $this->log('cancelPayment', 0, $cancelResponse->toJson());
        $newStatus = $this->adapter->getCancelStatus($cancelResponse);

        $this->saveOrderCustomFields($newStatus, $hostedCheckoutId);
        if (!in_array($newStatus, Payment::STATUS_PAYMENT_CANCELLED)) {
            $this->updateOrderTransactionState($newStatus, $hostedCheckoutId);
            return false;
        }
        return true;
    }

    /**
     * @param string $hostedCheckoutId
     * @return bool
     */
    public function refundPayment(string $hostedCheckoutId)
    {
        $status = $this->updatePaymentTransactionStatus($hostedCheckoutId);
        if (!in_array($status, Payment::STATUS_CAPTURED)) {
            $this->log('operationIsNotPossibleDueToCurrentStatus' . $status, Logger::ERROR);
            return false;
        }

        $amount = $this->orderTransaction->getOrder()->getAmountTotal();

        $currencyISO = $this->getCurrencyISO();
        if ($currencyISO === false) {
            return false;
        }

        $orderNumber = $this->orderTransaction->getOrder()->getOrderNumber();

        $refundResponse = $this->adapter->refundPayment(
            $hostedCheckoutId,
            $amount,
            $currencyISO,
            $orderNumber
        );

        $this->log('refundPayment', 0, $refundResponse->toJson());
        $status = $this->adapter->getRefundStatus($refundResponse);
        $this->saveOrderCustomFields($status, $hostedCheckoutId);
        if (!in_array($status, Payment::STATUS_REFUNDED)) {
            $this->updateOrderTransactionState($status, $hostedCheckoutId);
            return false;
        }
        return true;
    }

    /**
     * @param string $hostedCheckoutId
     * @return void
     * @throws \Exception
     */
    private function updatePaymentTransactionStatus(string $hostedCheckoutId): string
    {
        $this->log('gettingPaymentDetails', 0, ['hostedCheckoutId' => $hostedCheckoutId]);
        $paymentDetails = $this->adapter->getPaymentDetails($hostedCheckoutId);
        $status = $this->adapter->getStatus($paymentDetails);
        $this->saveOrderCustomFields($status, $hostedCheckoutId);
        return $status;
    }

    /**
     * @param int $statusCode
     * @param string $hostedCheckoutId
     * @return void
     */
    private function saveOrderCustomFields(int $statusCode, string $hostedCheckoutId)
    {
        $orderId = $this->orderTransaction->getOrder()->getId();
        $transactionId = $this->orderTransaction->getId();

        $customFields = [
            Form::CUSTOM_FIELD_WORDLINE_PAYMENT_HOSTED_CHECKOUT_ID => $hostedCheckoutId,
            Form::CUSTOM_FIELD_WORDLINE_PAYMENT_TRANSACTION_STATUS => (string)$statusCode,
        ];

        $this->orderTransactionRepository->update([
            [
                'id' => $transactionId,
                'customFields' => $customFields
            ]
        ], $this->context);

        $this->orderRepository->update([
            [
                'id' => $orderId,
                'customFields' => $customFields
            ]
        ], $this->context);
    }

    /**
     * @param int $statusCode
     * @param string $hostedCheckoutId
     * @return void
     */
    public function updateOrderTransactionState(int $statusCode, string $hostedCheckoutId)
    {
        $orderTransactionId = $this->orderTransaction->getId();
        $orderTransactionState = $this->orderTransaction->getStateMachineState()->getTechnicalName();

        switch ($statusCode) {
            case in_array($statusCode, Payment::STATUS_PAYMENT_CREATED):
            case in_array($statusCode, Payment::STATUS_PENDING_CAPTURE):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_OPEN) {
                    break;
                }
                $this->log(
                    'paymentOpen',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->reopen($orderTransactionId, $this->context);
                break;
            }
            case in_array($statusCode, Payment::STATUS_CAPTURE_REQUESTED):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_IN_PROGRESS) {
                    break;
                }
                $this->log(
                    'paymentInProgress',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->process($orderTransactionId, $this->context);
                break;
            }
            case in_array($statusCode, Payment::STATUS_CAPTURED):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_PAID) {
                    break;
                }
                $this->log(
                    'paymentPaid',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->paid($orderTransactionId, $this->context);
                break;
            }
            case in_array($statusCode, Payment::STATUS_REFUND_REQUESTED):
            case in_array($statusCode, Payment::STATUS_REFUNDED):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_REFUNDED) {
                    break;
                }
                $this->log(
                    'paymentRefunded',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->refund($orderTransactionId, $this->context);
                break;
            }
            case in_array($statusCode, Payment::STATUS_PAYMENT_CANCELLED):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_CANCELLED) {
                    break;
                }
                $this->log(
                    'paymentCanceled',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->cancel($orderTransactionId, $this->context);
                break;
            }
            case in_array($statusCode, Payment::STATUS_PAYMENT_REJECTED):
            case in_array($statusCode, Payment::STATUS_REJECTED_CAPTURE):
            {
                if ($orderTransactionState === OrderTransactionStates::STATE_FAILED) {
                    break;
                }
                $this->log(
                    'paymentFailed',
                    0,
                    ['status' => $statusCode, 'hostedCheckoutId' => $hostedCheckoutId]
                );
                $this->transactionStateHandler->fail($orderTransactionId, $this->context);
                break;
            }
            default:
            {
                break;
            }
        }
    }

    /**
     * @param Context $context
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param string $hostedCheckoutId
     * @return OrderTransactionEntity|null
     */
    public static function getOrderTransaction(
        Context                   $context,
        EntityRepositoryInterface $orderTransactionRepository,
        string                    $hostedCheckoutId
    ): ?OrderTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addAssociation('order');
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_AND,
                [
                    new EqualsFilter(
                        \sprintf('customFields.%s', Form::CUSTOM_FIELD_WORDLINE_PAYMENT_HOSTED_CHECKOUT_ID),
                        $hostedCheckoutId
                    ),
                    new NotFilter(
                        NotFilter::CONNECTION_AND,
                        [
                            new EqualsFilter(
                                \sprintf('customFields.%s', Form::CUSTOM_FIELD_WORDLINE_PAYMENT_HOSTED_CHECKOUT_ID),
                                null
                            ),
                        ]
                    ),
                ]
            )
        );

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = $orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        if ($orderTransaction === null) {
            throw new InvalidTransactionException('');
        }

        return $orderTransaction;
    }

    /**
     * @param string $string
     * @param int $logLevel
     * @param mixed $additionalData
     * @return void
     */
    private function log(string $string, int $logLevel = 0, $additionalData = null)
    {
        $additionalData = array_merge(
            [$additionalData],
            ['orderNumber' => $this->orderTransaction->getOrder()->getOrderNumber()]
        );

        $this->adapter->log(
            $this->translator->trans($string),
            $logLevel,
            $additionalData
        );
    }

    /**
     * @param string $currencyId
     * @return false|mixed|mixed[]
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    private function getCurrencyISO()
    {
        $currencyId = $this->orderTransaction->getOrder()->getCurrencyId();

        $connection = Kernel::getConnection();
        $sql = "SELECT iso_code  FROM `currency` WHERE id = UNHEX('$currencyId')";
        $currencyISO = $connection->executeQuery($sql)->fetchAssociative();

        if (array_key_exists('iso_code', $currencyISO)) {
            return $currencyISO['iso_code'];
        }

        $this->log('cantFindCurrencyOfOrder' . $currencyId, Logger::ERROR);
        return false;
    }
}
