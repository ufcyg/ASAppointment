<?php declare(strict_types=1);

namespace ASAppointment\Storefront\Controller;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class ClearCartController extends StorefrontController
{
    /** @var CartService $cartService */
    private $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * @Route("/cart/clear", name="frontend.checkout.clearCart", options={"seo"="false"}, methods={"GET"})
     */
    public function clearCart(SalesChannelContext $context)
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        foreach($cart->getLineItems() as $lineItemID => $lineItem)
        {
            $var = $cart->getLineItems();
            // $this->cartService->remove($cart, $lineItemID, $context);
            $this->cartService->add($cart, $cart->getLineItems()->first(), $context);
        }

        return $this->forwardToRoute('frontend.checkout.cart.page');
    }
}