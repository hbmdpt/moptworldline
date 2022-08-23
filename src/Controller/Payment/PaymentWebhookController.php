<?php declare(strict_types=1);

/**
 * @author Mediaopt GmbH
 * @package MoptWorldline\Controller
 */

namespace MoptWorldline\Controller\Payment;

use Symfony\Component\HttpFoundation\InputBag;
use MoptWorldline\Service\AdminTranslate;
use MoptWorldline\Service\PaymentHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidTransactionException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;
use Monolog\Logger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PaymentWebhookController extends AbstractController
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
     *     "/worldline/payment/webhook",
     *     name="worldline.payment.webhook",
     *     defaults={"csrf_protected"=false},
     *     methods={"POST"}
     * )
     * @throws InvalidTransactionException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function webhook(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $data = $this->parseRequest($request->request);
        if ($data === false) {
            return new Response();
        }

        try {
            /** @var OrderTransactionEntity|null $orderTransaction */
            $orderTransaction = PaymentHandler::getOrderTransaction(
                $salesChannelContext->getContext(),
                $this->orderTransactionRepository,
                $data['hostedCheckoutId']
            );
        } catch (\Exception $e) {
            $this->log($e->getMessage(), $request->request->all());
            return new Response();
        }

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
        $paymentHandler->logWebhook($request->request->all());

        $paymentHandler->updatePaymentStatus($data['hostedCheckoutId'], $data['statusCode']);

        return new Response();
    }

    /**
     * @param InputBag $request
     * @return array|false
     */
    private function parseRequest(InputBag $request): ?array
    {
        $id = $request->get('id');
        $type = $request->get('type');
        $merchantId = $request->get('merchantId');
        $payment = $request->get('payment');

        $hostedCheckoutId = false;
        if (is_array($payment) && array_key_exists('id', $payment)) {
            $paymentId = explode('_', $payment['id']);
            if (!empty($paymentId[0])) {
                $hostedCheckoutId = $paymentId[0];
            }
        }

        $statusCode = false;
        if (is_array($payment) && array_key_exists('statusOutput', $payment)) {
            if ($payment['statusOutput'] && array_key_exists('statusCode', $payment['statusOutput'])) {
                $statusCode = $payment['statusOutput']['statusCode'];
            }
        }

        if ($statusCode === false || $hostedCheckoutId === false) {
            $this->log('wrongRequestFormat', $request->all());
            return false;
        }

        return [
            'id' => $id,
            'type' => $type,
            'merchantId' => $merchantId,
            'payment' => $payment,
            'hostedCheckoutId' => $hostedCheckoutId,
            'statusCode' => $statusCode
        ];
    }

    /**
     * @param $message
     * @param $additionalData
     * @return void
     */
    private function log($message, $additionalData)
    {
        $this->logger->addRecord(
            Logger::ERROR,
            AdminTranslate::trans($this->translator->getLocale(), $message),
            [
                'source' => 'Worldline',
                'additionalData' => $additionalData,
            ]
        );
    }
}
