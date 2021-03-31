<?php declare(strict_types=1);

namespace ASAppointment\Subscriber;

use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\Event\LineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 
 * 
 * 
 */
class LineItemEventSubscriber implements EventSubscriberInterface
{
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;
    /** @var ContainerInterface $container */
    protected $container;
    /** @var RequestStack $requestStack */
    private $requestStack;
    
    public function __construct(SystemConfigService $systemConfigService,
                                RequestStack $requestStack)
    {
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
    }
    /** @internal @required */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }
    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            LineItemAddedEvent::class => 'onLineItemAddedEvent',
        ];
    }

    public function onLineItemAddedEvent(LineItemAddedEvent $event): void
    {
        // $var = $this->requestStack->getCurrentRequest();
        // $lineItem = $event->getLineItem();
        // foreach ($this->requestStack->getCurrentRequest()->get('lineItems') as $key =>  $item) {
        //     if ($lineItem->getReferencedId() == $key && isset($item['customData'])) {
        //         $lineItem->setPayloadValue('customData', $item['customData']);
        //     }
        //     if ($lineItem->getReferencedId() == $key && isset($item['isSample'])) {
        //         $lineItem->setPayloadValue('isSample', $item['isSample']);
        //     }
        //     if ($lineItem->getReferencedId() == $key && isset($item['sampleNo'])) {
        //         $lineItem->setPayloadValue('sampleNo', $item['sampleNo']);
        //     }
        // }
    }
}