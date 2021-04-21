<?php declare(strict_types=1);

namespace ASAppointment\Storefront\Controller;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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

    private function writeLineItemsCustomField(LineItemCollection $cartLineItems, $context)
    {
        foreach($cartLineItems as $currentLineItemId => $currentLineItem){
            $this->writeLineItemCustomField($currentLineItem, $context);
        }
    }

    private function writeLineItemCustomField(LineItem $lineItem, $context)
    {
        /** @var EntityRepositoryInterface $lineItemRepository */
        $lineItemRepository = $this->container->get('order_line_item.repository');



        $lineItemRepository->upsert([[
            'id' => $lineItem->getId(),
            '' => ['appointment_shipment_date' => $this->getSomeDate()]
        ]], $context);
    }

    private function getSomeDate()
    {
        $monthOffset = 0;
        $dayOffset = random_int(-15,0);
        //define first and last day of the month
        $firstDayUTS = mktime (0, 0, 0, intval(date("n"))-$monthOffset, 1-$dayOffset, intval(date("Y")));
        $lastDayUTS = mktime (0, 0, 0, intval(date("n"))-$monthOffset, cal_days_in_month(CAL_GREGORIAN, intval(date("n"))-$dayOffset, intval(date("Y"))), intval(date("Y")));
        //generate strings to compare with entries in DB through DBAL
        $firstDay = date("Y-m-d", $firstDayUTS);
        // $firstDay = $firstDay . " 00:00:00.000";
        $lastDay = date("Y-m-d", $lastDayUTS);
        // $lastDay = $lastDay . " 23:59:59.999";
        return $lastDay;
    }










    public function getAllEntitiesOfRepository(EntityRepositoryInterface $repository, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

        return $result;
    }

    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

        return $result;
    }

    public function entityExistsInRepositoryCk(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): bool
    {
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));

        /** @var EntitySearchResult $searchResult */
        $searchResult = $repository->search($criteria, $context);
        
        return count($searchResult) != 0 ? true : false;
    }
}