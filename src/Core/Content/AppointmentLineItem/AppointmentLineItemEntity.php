<?php declare(strict_types=1);

namespace ASAppointment\Core\Content\AppointmentLineItem;

use DateTimeImmutable;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class AppointmentLineItemEntity extends Entity
{
    use EntityIdTrait;
    
    /** @var string */
    protected $productNumber;
    /** @var int */
    protected $amount;
    /** @var DateTimeImmutable */
    protected $appointmentDate;
    /** @var string */
    protected $customerId;

    /** Get the value of productNumber */ 
    public function getProductNumber()
    {
        return $this->productNumber;
    }

    /** Set the value of productNumber @return  self */ 
    public function setProductNumber($productNumber)
    {
        $this->productNumber = $productNumber;

        return $this;
    }

    /** Get the value of amount */ 
    public function getAmount()
    {
        return $this->amount;
    }

    /** Set the value of amount @return  self */ 
    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /** Get the value of appointmentDate */ 
    public function getAppointmentDate()
    {
        return $this->appointmentDate;
    }

    /** Set the value of appointmentDate @return  self */ 
    public function setAppointmentDate($appointmentDate)
    {
        $this->appointmentDate = $appointmentDate;

        return $this;
    }

    /** Get the value of customerId */ 
    public function getCustomerId()
    {
        return $this->customerId;
    }

    /** Set the value of customerId @return  self */ 
    public function setCustomerId($customerId)
    {
        $this->customerId = $customerId;

        return $this;
    }
}