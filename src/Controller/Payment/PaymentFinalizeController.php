<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWordline\Controller
 */

namespace MoptWordline\Controller\Payment;

use MoptWordline\Adapter\WordlineSDKAdapter;
use MoptWordline\Service\AdminTranslate;
use MoptWordline\Service\PaymentHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Checkout\Payment\Exception\PaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Monolog\Logger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PaymentFinalizeController extends AbstractController
{
    private RouterInterface $router;

    private EntityRepositoryInterface $orderTransactionRepository;

    private EntityRepositoryInterface $orderRepository;

    private AsynchronousPaymentHandlerInterface $paymentHandler;

    private OrderTransactionStateHandler $transactionStateHandler;

    private SystemConfigService $systemConfigService;

    private Logger $logger;

    private TranslatorInterface $translator;

    public function __construct(
        SystemConfigService                 $systemConfigService,
        EntityRepositoryInterface           $orderTransactionRepository,
        EntityRepositoryInterface           $orderRepository,
        AsynchronousPaymentHandlerInterface $paymentHandler,
        OrderTransactionStateHandler        $transactionStateHandler,
        RouterInterface                     $router,
        Logger                              $logger,
        TranslatorInterface                 $translator
    )
    {
        $this->systemConfigService = $systemConfigService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->orderRepository = $orderRepository;
        $this->paymentHandler = $paymentHandler;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->router = $router;
        $this->logger = $logger;
        $this->translator = $translator;
    }

    /**
     * @Route(
     *     "/wordline/payment/finalize-transaction",
     *     name="wordline.payment.finalize.transaction",
     *     methods={"GET"}
     * )
     * @throws InvalidTransactionException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $hostedCheckoutId = $request->query->get('hostedCheckoutId');
        if (is_null($hostedCheckoutId)) {
            return new RedirectResponse('/');
        }
        $context = $salesChannelContext->getContext();

        /** @var OrderTransactionEntity|null $orderTransaction */
        $orderTransaction = PaymentHandler::getOrderTransaction($context, $this->orderTransactionRepository, $hostedCheckoutId);

        $paymentHandler = new PaymentHandler(
            $this->systemConfigService,
            $this->logger,
            $orderTransaction,
            $this->translator,
            $this->orderRepository,
            $this->orderTransactionRepository,
            $salesChannelContext->getContext(),
            $this->transactionStateHandler
        );

        $paymentHandler->updatePaymentStatus($hostedCheckoutId);

        $finishUrl = $this->buildFinishUrl($request, $orderTransaction, $salesChannelContext, $context);

        return new RedirectResponse($finishUrl);
    }

    /**
     * @param Request $request
     * @param OrderTransactionEntity $orderTransaction
     * @param SalesChannelContext $salesChannelContext
     * @param Context $context
     * @return string
     */
    private function buildFinishUrl(
        Request                $request,
        OrderTransactionEntity $orderTransaction,
        SalesChannelContext    $salesChannelContext,
        Context                $context
    ): string
    {
        $order = $orderTransaction->getOrder();

        if ($order === null) {
            throw new InvalidTransactionException($orderTransaction->getId());
        }

        $paymentTransactionStruct = new AsyncPaymentTransactionStruct($orderTransaction, $order, '');

        $orderId = $order->getId();
        $changedPayment = $request->query->getBoolean('changedPayment');
        $finishUrl = $this->router->generate('frontend.checkout.finish.page', [
            'orderId' => $orderId,
            'changedPayment' => $changedPayment,
        ]);

        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $adapter = new WordlineSDKAdapter($this->systemConfigService, $this->logger, $salesChannelId);
        try {
            $adapter->log(AdminTranslate::trans($this->translator->getLocale(), 'forwardToPaymentHandler'));
            $this->paymentHandler->finalize($paymentTransactionStruct, $request, $salesChannelContext);
        } catch (PaymentProcessException $paymentProcessException) {
            $adapter->log(
                AdminTranslate::trans($this->translator->getLocale(), 'errorWithConfirmRedirect'),
                Logger::ERROR,
                ['message' => $paymentProcessException->getMessage(), 'error' => $paymentProcessException]
            );
            $finishUrl = $this->redirectToConfirmPageWorkflow(
                $paymentProcessException,
                $context,
                $orderId,
                $order->getSalesChannelId()
            );
        }

        return $finishUrl;
    }

    /**
     * @param PaymentProcessException $paymentProcessException
     * @param Context $context
     * @param string $orderId
     * @param string $salesChannelId
     * @return string
     */
    private function redirectToConfirmPageWorkflow(
        PaymentProcessException $paymentProcessException,
        Context                 $context,
        string                  $orderId,
        string                  $salesChannelId
    ): string
    {
        $errorUrl = $this->router->generate('frontend.account.edit-order.page', ['orderId' => $orderId]);

        if ($paymentProcessException instanceof CustomerCanceledAsyncPaymentException) {
            $this->transactionStateHandler->cancel(
                $paymentProcessException->getOrderTransactionId(),
                $context
            );
            $urlQuery = \parse_url($errorUrl, \PHP_URL_QUERY) ? '&' : '?';

            return \sprintf('%s%serror-code=%s', $errorUrl, $urlQuery, $paymentProcessException->getErrorCode());
        }

        $transactionId = $paymentProcessException->getOrderTransactionId();

        $adapter = new WordlineSDKAdapter($this->systemConfigService, $this->logger, $salesChannelId);
        $adapter->log(
            $paymentProcessException->getMessage(),
            Logger::ERROR,
            ['orderTransactionId' => $transactionId, 'error' => $paymentProcessException]
        );
        $this->transactionStateHandler->fail(
            $transactionId,
            $context
        );
        $urlQuery = \parse_url($errorUrl, \PHP_URL_QUERY) ? '&' : '?';

        return \sprintf('%s%serror-code=%s', $errorUrl, $urlQuery, $paymentProcessException->getErrorCode());
    }
}
