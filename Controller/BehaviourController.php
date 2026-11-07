<?php

declare(strict_types=1);

namespace MauticPlugin\EcommerceConnectorBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\FormController;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviour;
use MauticPlugin\EcommerceConnectorBundle\Entity\EcommerceBehaviourRepository;
use MauticPlugin\EcommerceConnectorBundle\Form\Type\BehaviourType;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourCalculator;
use MauticPlugin\EcommerceConnectorBundle\Service\BehaviourFieldManager;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behaviours create and drop contact fields, so they need the custom field permission.
 */
class BehaviourController extends FormController
{
    private const PERMISSION = 'lead:fields:full';

    private const ACTIVE_LINK = '#mautic_ecommerce_behaviour_index';

    private const MAUTIC_CONTENT = 'ecommercebehaviour';

    public function indexAction(EcommerceBehaviourRepository $behaviourRepository): Response
    {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters'  => [
                'items' => $behaviourRepository->findAllOrdered(),
            ],
            'contentTemplate' => '@EcommerceConnector/Behaviour/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => self::ACTIVE_LINK,
                'route'         => $this->generateUrl('mautic_ecommerce_behaviour_index'),
                'mauticContent' => self::MAUTIC_CONTENT,
            ],
        ]);
    }

    public function newAction(
        Request $request,
        EntityManagerInterface $entityManager,
        BehaviourFieldManager $fieldManager,
        BehaviourCalculator $calculator,
    ): Response {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $behaviour = new EcommerceBehaviour();
        $action    = $this->generateUrl('mautic_ecommerce_behaviour_action', ['objectAction' => 'new']);

        return $this->handleForm($request, $behaviour, $action, $entityManager, $fieldManager, $calculator);
    }

    public function editAction(
        Request $request,
        EcommerceBehaviourRepository $behaviourRepository,
        EntityManagerInterface $entityManager,
        BehaviourFieldManager $fieldManager,
        BehaviourCalculator $calculator,
        int $objectId,
    ): Response {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $behaviour = $behaviourRepository->find($objectId);
        if (!$behaviour instanceof EcommerceBehaviour) {
            return $this->redirectToList([$this->notFoundFlash($objectId)]);
        }

        $action = $this->generateUrl('mautic_ecommerce_behaviour_action', ['objectAction' => 'edit', 'objectId' => $objectId]);

        return $this->handleForm($request, $behaviour, $action, $entityManager, $fieldManager, $calculator);
    }

    public function deleteAction(
        Request $request,
        EcommerceBehaviourRepository $behaviourRepository,
        EntityManagerInterface $entityManager,
        BehaviourFieldManager $fieldManager,
        int $objectId,
    ): Response {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        if ('POST' !== $request->getMethod()) {
            return $this->redirectToList([]);
        }

        $behaviour = $behaviourRepository->find($objectId);
        if (!$behaviour instanceof EcommerceBehaviour) {
            return $this->redirectToList([$this->notFoundFlash($objectId)]);
        }

        // Same rule as for custom fields: a field used by a segment cannot be removed.
        $segments = $fieldManager->getSegmentsUsingField($behaviour);
        if ([] !== $segments) {
            return $this->redirectToList([[
                'type'    => 'error',
                'msg'     => 'mautic.core.notice.used.field',
                'msgVars' => [
                    '%name%'     => $behaviour->getName(),
                    '%id%'       => $objectId,
                    '%segments%' => implode(', ', $segments),
                ],
            ]]);
        }

        $fieldManager->deleteField($behaviour);
        $entityManager->remove($behaviour);
        $entityManager->flush();

        return $this->redirectToList([[
            'type'    => 'notice',
            'msg'     => 'mautic.core.notice.deleted',
            'msgVars' => ['%name%' => $behaviour->getName(), '%id%' => $objectId],
        ]]);
    }

    public function recalculateAction(
        Request $request,
        EcommerceBehaviourRepository $behaviourRepository,
        BehaviourCalculator $calculator,
        int $objectId,
    ): Response {
        if (!$this->security->isGranted(self::PERMISSION)) {
            return $this->accessDenied();
        }

        $behaviour = 'POST' === $request->getMethod() ? $behaviourRepository->find($objectId) : null;
        if (!$behaviour instanceof EcommerceBehaviour) {
            return $this->redirectToList([$this->notFoundFlash($objectId)]);
        }

        $count = $calculator->recalculate($behaviour);

        return $this->redirectToList([[
            'type'    => 'notice',
            'msg'     => 'mautic.plugin.ecommerce.behaviour.recalculated',
            'msgVars' => ['%name%' => $behaviour->getName(), '%count%' => $count],
        ]]);
    }

    private function handleForm(
        Request $request,
        EcommerceBehaviour $behaviour,
        string $action,
        EntityManagerInterface $entityManager,
        BehaviourFieldManager $fieldManager,
        BehaviourCalculator $calculator,
    ): Response {
        $form = $this->formFactory->create(BehaviourType::class, $behaviour, ['action' => $action]);

        if ('POST' === $request->getMethod()) {
            if ($this->isFormCancelled($form)) {
                return $this->redirectToList([]);
            }

            if ($this->isFormValid($form) && $this->saveBehaviour($form, $behaviour, $entityManager, $fieldManager)) {
                $count = $behaviour->isPublished() ? $calculator->recalculate($behaviour) : 0;

                $this->addFlashMessage('mautic.plugin.ecommerce.behaviour.saved', [
                    '%name%'  => $behaviour->getName(),
                    '%alias%' => (string) $behaviour->getFieldAlias(),
                    '%count%' => $count,
                ]);

                if ($this->getFormButton($form, ['buttons', 'save'])->isClicked()) {
                    return $this->redirectToList([]);
                }

                $editUrl = $this->generateUrl('mautic_ecommerce_behaviour_action', ['objectAction' => 'edit', 'objectId' => $behaviour->getId()]);
                $form    = $this->formFactory->create(BehaviourType::class, $behaviour, ['action' => $editUrl]);
            }
        }

        return $this->delegateView([
            'viewParameters'  => [
                'form'      => $form->createView(),
                'behaviour' => $behaviour,
            ],
            'contentTemplate' => '@EcommerceConnector/Behaviour/form.html.twig',
            'passthroughVars' => [
                'activeLink'    => self::ACTIVE_LINK,
                'route'         => $action,
                'mauticContent' => self::MAUTIC_CONTENT,
            ],
        ]);
    }

    /**
     * @param FormInterface<EcommerceBehaviour> $form
     */
    private function saveBehaviour(
        FormInterface $form,
        EcommerceBehaviour $behaviour,
        EntityManagerInterface $entityManager,
        BehaviourFieldManager $fieldManager,
    ): bool {
        $isNew = null === $behaviour->getId();

        try {
            $fieldManager->saveField($behaviour, $isNew ? (string) $form->get('alias')->getData() : null);
        } catch (\Exception $exception) {
            $form->get('alias')->addError(new FormError($this->translator->trans(
                'mautic.lead.field.failed',
                ['%error%' => $exception->getMessage()],
                'validators'
            )));

            return false;
        }

        if ($isNew) {
            $behaviour->setDateAdded(new \DateTime());
        }

        $entityManager->persist($behaviour);
        $entityManager->flush();

        return true;
    }

    /**
     * @param list<array<string, mixed>> $flashes
     */
    private function redirectToList(array $flashes): Response
    {
        return $this->postActionRedirect([
            'returnUrl'       => $this->generateUrl('mautic_ecommerce_behaviour_index'),
            'contentTemplate' => static::class.'::indexAction',
            'passthroughVars' => [
                'activeLink'    => self::ACTIVE_LINK,
                'mauticContent' => self::MAUTIC_CONTENT,
            ],
            'flashes'         => $flashes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function notFoundFlash(int $objectId): array
    {
        return [
            'type'    => 'error',
            'msg'     => 'mautic.plugin.ecommerce.behaviour.notfound',
            'msgVars' => ['%id%' => $objectId],
        ];
    }
}
