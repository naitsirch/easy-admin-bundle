<?php

/*
 * This file is part of the EasyAdminBundle.
 *
 * (c) Javier Eguiluz <javier.eguiluz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Form\Type;

use ArrayObject;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\ConfigManager;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\Configurator\TypeConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Form\Util\LegacyFormHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * Custom form type that deals with some of the logic used to render the
 * forms used to create and edit EasyAdmin entities.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class EasyAdminFormType extends AbstractType
{
    /** @var ConfigManager */
    private $configManager;

    /** @var TypeConfiguratorInterface[] */
    private $configurators;

    /**
     * @param ConfigManager               $configManager
     * @param TypeConfiguratorInterface[] $configurators
     */
    public function __construct(ConfigManager $configManager, array $configurators = array())
    {
        $this->configManager = $configManager;
        $this->configurators = $configurators;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $entity = $options['entity'];
        $view = $options['view'];
        $entityConfig = $this->configManager->getEntityConfig($entity);
        $entityProperties = isset($entityConfig[$view]['fields']) ? $entityConfig[$view]['fields'] : array();
        $formTabs = array();
        $currentFormTab = null;
        $formGroups = array();
        $currentFormGroup = null;

        foreach ($entityProperties as $name => $metadata) {
            $formFieldOptions = $metadata['type_options'];

            // Configure options using the list of registered type configurators:
            foreach ($this->configurators as $configurator) {
                if ($configurator->supports($metadata['fieldType'], $formFieldOptions, $metadata)) {
                    $formFieldOptions = $configurator->configure($name, $formFieldOptions, $metadata, $builder);
                }
            }

            $formFieldType = LegacyFormHelper::getType($metadata['fieldType']);

            // if the form field is a special 'group' design element, don't add it
            // to the form. Instead, consider it the current form group (this is
            // applied to the form fields defined after it) and store its details
            // in a property to get them in form template
            if (in_array($formFieldType, array('easyadmin_group', 'EasyCorp\\Bundle\\EasyAdminBundle\\Form\\Type\\EasyAdminGroupType'))) {
                $metadata['form_tab'] = $currentFormTab ?: null;
                $currentFormGroup = $metadata['fieldName'];
                $formGroups[$currentFormGroup] = $metadata;

                continue;
            }

            // if the form field is a special 'tab' design element, don't add it
            // to the form. Instead, consider it the current form group (this is
            // applied to the form fields defined after it) and store its details
            // in a property to get them in form template
            if (in_array($formFieldType, array('easyadmin_tab', 'EasyCorp\\Bundle\\EasyAdminBundle\\Form\\Type\\EasyAdminTabType'))) {
                // The first tab should be marked as active by default
                $metadata['active'] = count($formTabs) === 0;
                $metadata['errors'] = 0;
                $currentFormTab = $metadata['fieldName'];

                // For a form tab a plain array is not enough, because we need to be able to modify it in the
                // lifecycle of a form (e.g. add info about form errors). So we'll use an ArrayObject.
                $formTabs[$currentFormTab] = new ArrayObject($metadata);

                continue;
            }

            // 'divider' and 'section' are 'fake' form fields used to create the design
            // elements of the complex form layouts: define them as unmapped and non-required
            if (0 === strpos($metadata['property'], '_easyadmin_form_design_element_')) {
                $formFieldOptions['mapped'] = false;
                $formFieldOptions['required'] = false;
            }

            $formField = $builder->getFormFactory()->createNamedBuilder($name, $formFieldType, null, $formFieldOptions);
            $formField->setAttribute('easyadmin_form_tab', $currentFormTab);
            $formField->setAttribute('easyadmin_form_group', $currentFormGroup);

            $builder->add($formField);
        }

        $builder->setAttribute('easyadmin_form_tabs', $formTabs);
        $builder->setAttribute('easyadmin_form_groups', $formGroups);

        if (count($formTabs) > 0) {
            $listenerClosure = function(FormEvent $event) use ($formTabs) {
                $activeTab = null;
                foreach ($event->getForm() as $child) {
                    $errors = $child->getErrors(true);

                    if (count($errors) > 0) {
                        $formTab = $child->getConfig()->getAttribute('easyadmin_form_tab');
                        $formTabs[$formTab]['errors'] += count($errors);

                        if (null === $activeTab) {
                            $activeTab = $formTab;
                        }
                    }
                }

                $firstTab = key($formTabs);
                if ($firstTab !== $activeTab) {
                    // We have to deactivate the first tab, so that the first tab with
                    // eroneous data is shown
                    $formTabs[$firstTab]['active'] = false;
                    $formTabs[$activeTab]['active'] = true;
                }
            };
            $builder->addEventListener(FormEvents::POST_SUBMIT, $listenerClosure, -1);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function finishView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['easyadmin_form_tabs'] = $form->getConfig()->getAttribute('easyadmin_form_tabs');
        $view->vars['easyadmin_form_groups'] = $form->getConfig()->getAttribute('easyadmin_form_groups');
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $configManager = $this->configManager;

        $resolver
            ->setDefaults(array(
                'allow_extra_fields' => true,
                'data_class' => function (Options $options) use ($configManager) {
                    $entity = $options['entity'];
                    $entityConfig = $configManager->getEntityConfig($entity);

                    return $entityConfig['class'];
                },
            ))
            ->setRequired(array('entity', 'view'));

        // setNormalizer() is available since Symfony 2.6
        if (method_exists($resolver, 'setNormalizer')) {
            $resolver->setNormalizer('attr', $this->getAttributesNormalizer());
        } else {
            // BC for Symfony < 2.6
            $resolver->setNormalizers(array('attr' => $this->getAttributesNormalizer()));
        }
    }

    // BC for SF < 2.7
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $this->configureOptions($resolver);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'easyadmin';
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->getBlockPrefix();
    }

    /**
     * Returns a closure normalizing the form html attributes.
     *
     * @return \Closure
     */
    private function getAttributesNormalizer()
    {
        return function (Options $options, $value) {
            return array_replace(array(
                'id' => sprintf('%s-%s-form', $options['view'], mb_strtolower($options['entity'])),
            ), $value);
        };
    }
}

class_alias('EasyCorp\Bundle\EasyAdminBundle\Form\Type\EasyAdminFormType', 'JavierEguiluz\Bundle\EasyAdminBundle\Form\Type\EasyAdminFormType', false);
