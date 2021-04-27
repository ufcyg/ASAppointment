<?php

declare(strict_types=1);

namespace ASAppointment;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;

class ASAppointment extends Plugin
{
    /** @inheritDoc */
    public function install(InstallContext $installContext): void
    {
    }

    /** @inheritDoc */
    public function postInstall(InstallContext $installContext): void
    {
    }

    /** @inheritDoc */
    public function update(UpdateContext $updateContext): void
    {
    }

    /** @inheritDoc */
    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    /** @inheritDoc */
    public function activate(ActivateContext $activateContext): void
    {
        $context = $activateContext->getContext();

        /** @var EntityRepositoryInterface $stateMachineRepository */
        $stateMachineRepository = $this->container->get('state_machine.repository');
        /** @var StateMachineEntity $stateMachine */
        $stateMachine = $this->getFilteredEntitiesOfRepository(
            $stateMachineRepository,
            'technicalName',
            'order.state',
            $context
        )->first();
        /** @var EntityRepositoryInterface $stateMachineStateRepository */
        $stateMachineStateRepository = $this->container->get('state_machine_state.repository');
        /** @var StateMachineStateEntity */
        // add state
        $this->addState($stateMachineStateRepository, $stateMachine, 'appointed', $context);
        $this->addState($stateMachineStateRepository, $stateMachine, 'cancelledAppointment', $context);
        //add state translations
        $this->addStateTranslation($stateMachineStateRepository, 'appointed', 'Terminbestellung', 'Appointmentorder',  $context);
        $this->addStateTranslation($stateMachineStateRepository, 'cancelledAppointment', 'Terminbestellung(abgebrochen)', 'Appointmentorder(cancelled)',  $context);
        // add transitions
        //get open state machine state
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));
        $criteria->addFilter(new EqualsFilter('technicalName', 'open'));
        /** @var StateMachineStateEntity $openState */
        $openState = $stateMachineStateRepository->search($criteria, $context)->first();
        //get cancelledAppointment state machine state
        /** @var StateMachineStateEntity $cancelledState */
        $cancelledState = $this->getFilteredEntitiesOfRepository($stateMachineStateRepository, 'technicalName', 'cancelledAppointment', $context)->first();

        /** @var StateMachineStateEntity $stateEntity */
        $appointedStateEntity = $this->getFilteredEntitiesOfRepository($stateMachineStateRepository, 'technicalName', 'appointed', $context)->first();
        //add open to appointed transition
        $this->addStateTransition($stateMachine->getId(), $openState->getId(), $appointedStateEntity->getId(), 'setAppointed', $context);
        //add appointed to open transition
        $this->addStateTransition($stateMachine->getId(), $appointedStateEntity->getId(), $openState->getId(), 'openAppointment', $context);
        //add appointed to cancelled transition
        $this->addStateTransition($stateMachine->getId(), $appointedStateEntity->getId(), $cancelledState->getId(), 'cancelAppointment', $context);
        //add cancelled to appointed transition
        $this->addStateTransition($stateMachine->getId(), $cancelledState->getId(), $appointedStateEntity->getId(), 'reopenAppointment', $context);

        // // set initial state of orders to Appointmentorder
        // $stateMachineRepository->update([['id' => $stateMachine->getId(), 'initialStateId' => $appointedStateEntity->getId()]], $context);
    }

    private function addState($stateMachineStateRepository, $stateMachine, $technicalName, $context)
    {
        if (!$this->entityExistsInRepositoryCk(
            $stateMachineStateRepository,
            'technicalName',
            $technicalName,
            $context
        )) {
            /** @var EntityWrittenContainerEvent $stateTransitionWrittenEvent */
            $stateTransitionWrittenEvent = $stateMachineStateRepository->create([[
                'name' => 'appointed',
                'technicalName' => $technicalName,
                'stateMachineId' => $stateMachine->getId()
            ]], $context);
        }
    }

    private function addStateTranslation($stateMachineStateRepository, $technicalName, $germanName, $englishName, $context)
    {
        /** @var EntityRepositoryInterface $languageRepository */
        $languageRepository = $this->container->get('language.repository');
        $languages = $this->getAllEntitiesOfRepository($languageRepository, $context);
        $germanID = null;
        $englishID = null;
        /** @var LanguageEntity $language */
        foreach ($languages as $languageID => $language) {
            switch ($language->getName()) {
                case 'Deutsch':
                    $germanID = $languageID;
                    break;
                case 'English':
                    $englishID = $languageID;
                    break;
            }
        }
        /** @var StateMachineStateEntity $stateEntity */
        $stateEntity = $this->getFilteredEntitiesOfRepository($stateMachineStateRepository, 'technicalName', $technicalName, $context)->first();
        /** @var EntityRepositoryInterface $stateMachineStateTranslationRepository */
        $stateMachineStateTranslationRepository = $this->container->get('state_machine_state_translation.repository');
        if ($germanID != null) {
            if (!$this->entityExistsInRepositoryCk($stateMachineStateTranslationRepository, 'name', $germanName, $context))
                $stateMachineStateTranslationRepository->upsert([
                    [
                        'languageId' => $germanID,
                        'stateMachineStateId' => $stateEntity->getId(),
                        'name' => $germanName
                    ]
                ], $context);
        }
        if ($englishID != null) {
            if (!$this->entityExistsInRepositoryCk($stateMachineStateTranslationRepository, 'name', $englishName, $context))
                $stateMachineStateTranslationRepository->upsert([
                    [
                        'languageId' => $englishID,
                        'stateMachineStateId' => $stateEntity->getId(),
                        'name' => $englishName
                    ]
                ], $context);
        }
    }

    private function addStateTransition($stateMachineID, $fromStateID, $toStateID, $actionName, Context $context)
    {
        /** @var EntityRepositoryInterface $stateMachineTransitionRepository */
        $stateMachineTransitionRepository = $this->container->get('state_machine_transition.repository');
        if (!$this->entityExistsInRepositoryCk($stateMachineTransitionRepository, 'actionName', $actionName, $context)) {
            $stateMachineTransitionRepository->create([[
                'actionName' => $actionName,
                'stateMachineId' => $stateMachineID,
                'fromStateId' => $fromStateID,
                'toStateId' => $toStateID
            ]], $context);
        }
    }

    /** @inheritDoc */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // remove transitions
        $context = $deactivateContext->getContext();
        /** @var EntityRepositoryInterface $stateMachineTransitionRepository */
        $stateMachineTransitionRepository = $this->container->get('state_machine_transition.repository');

        $this->removeEntity($stateMachineTransitionRepository, 'actionName', 'setAppointed', $context);
        $this->removeEntity($stateMachineTransitionRepository, 'actionName', 'openAppointment', $context);
        $this->removeEntity($stateMachineTransitionRepository, 'actionName', 'cancelAppointment', $context);
        $this->removeEntity($stateMachineTransitionRepository, 'actionName', 'reopenAppointment', $context);

        // // reset initial state of orders
        // /** @var EntityRepositoryInterface $stateMachineRepository */
        // $stateMachineRepository = $this->container->get('state_machine.repository');
        // $stateMachine = $this->getFilteredEntitiesOfRepository($stateMachineRepository, 'technicalName', 'order.state', $context)->first();
        // //get open state machine state
        // /** @var EntityRepositoryInterface $stateMachineRepository */
        // $stateMachineStateRepository = $this->container->get('state_machine_state.repository');
        // $criteria = new Criteria();
        // $criteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));
        // $criteria->addFilter(new EqualsFilter('technicalName', 'open'));
        // /** @var StateMachineStateEntity $openState */
        // $openStateEntity = $stateMachineStateRepository->search($criteria, $context)->first();
        // $stateMachineRepository->update([['id' => $stateMachine->getId(), 'initialStateId' => $openStateEntity->getId()]], $context);
    }

    private function removeEntity(EntityRepositoryInterface $repository, string $fieldName, string $fieldValue, Context $context)
    {
        $entity = $this->getFilteredEntitiesOfRepository($repository, $fieldName, $fieldValue, $context)->first();
        if ($entity != null)
            $repository->delete([['id' => $entity->getId()]], $context);
    }

    /** @inheritDoc */
    public function uninstall(UninstallContext $context): void
    {
        if ($context->keepUserData()) {
            parent::uninstall($context);

            return;
        }

        // $connection = $this->container->get(Connection::class);

        // $connection->executeUpdate('DROP TABLE IF EXISTS `as_appointment_line_item`');

        parent::uninstall($context);
    }



    public function getAllEntitiesOfRepository(EntityRepositoryInterface $repository, Context $context): ?EntitySearchResult
    {
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria, $context);

        return $result;
    }
    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria, $context);

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
