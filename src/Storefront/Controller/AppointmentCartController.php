<?php declare(strict_types=1);

namespace ASAppointment\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class AppointmentCartController extends StorefrontController
{
    /** @var CartService $cartService */
    private $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * @Route("/cart/add/appointment/{lineItemId}", name="frontend.checkout.addAppointmentLineItem", options={"seo"="false"}, methods={"GET"})
     */
    public function addAppointmentLineItem(Cart $cart, string $lineItemId, SalesChannelContext $context)
    {
        $cartLineItems = $cart->getLineItems();
        // $this->writeLineItemsCustomField($cartLineItems, $context->getContext());
        $currentlineItem = $cartLineItems->get($lineItemId);
        $productId = $currentlineItem->getReferencedId();

        $lineItem = new LineItem(Uuid::randomHex(), "product", NULL, 1);
        $lineItem->setGood(false);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(true);
        $lineItem->setReferencedId($productId);

        $this->cartService->add($cart, $lineItem, $context);
        
        return $this->forwardToRoute('frontend.checkout.confirm.page');
    }
}