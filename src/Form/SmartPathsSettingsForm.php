<?php

declare(strict_types=1);

namespace Drupal\leap_smartpaths\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\workflows\Entity\Workflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Smart Paths settings.
 *
 * This administrative form allows site builders to independently configure
 * two distinct behaviors for every workflow state:
 * 1. Opt-Out: Prevent the state from receiving or triggering cascading URL updates.
 * 2. Prefixing: Force a specific slug (e.g., 'archived') to the start of the URL.
 */
class SmartPathsSettingsForm extends ConfigFormBase {

  /**
   * Constructs a SmartPathsSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['leap_smartpaths.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'leap_smartpaths_settings_form';
  }

  /**
   * Helper: Extracts prefixes into a flat key-value map for form defaults.
   */
  private function getPrefixMap(array $config_prefixes): array {
    $map = [];
    foreach ($config_prefixes as $item) {
      if (isset($item['state_id'], $item['prefix'])) {
        $map[$item['state_id']] = $item['prefix'];
      }
    }
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('leap_smartpaths.settings');
    $opt_out_states = $config->get('opt_out_states') ?? [];
    $raw_prefixes = $config->get('state_prefixes') ?? [];
    $prefix_map = $this->getPrefixMap($raw_prefixes);

    $form['intro'] = [
      '#markup' => '<h3>' . $this->t('Smart Paths Workflow Configuration') . '</h3><p>' . $this->t('Configure how URL aliases behave when content enters specific moderation states. These two options operate independently and can be used in tandem.') . '</p>',
    ];

    $form['workflows'] = [
      '#tree' => TRUE,
    ];

    // Build the dynamic UI for each workflow.
    if ($this->moduleHandler->moduleExists('content_moderation')) {
      $workflows = Workflow::loadMultipleByType('content_moderation');
      foreach ($workflows as $workflow_id => $workflow) {
        $this->buildWorkflowGroup($form['workflows'], $workflow_id, $workflow->label(), $workflow->getTypePlugin()->getStates(), $opt_out_states, $prefix_map);
      }
    }

    // Always provide the Core Status fallback.
    $core_states = [
      'unpublished' => $this->t('Unpublished'),
      'published' => $this->t('Published'),
    ];
    $this->buildWorkflowGroup($form['workflows'], 'core', $this->t('Core Status Fallback (No Workflow)'), $core_states, $opt_out_states, $prefix_map);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Helper: Builds a table of configurations for a specific workflow.
   *
   * @param array $form_group
   *   The parent form element.
   * @param string $workflow_id
   *   The workflow ID.
   * @param mixed $workflow_label
   *   The display label for the workflow.
   * @param array $states
   *   The available states.
   * @param array $opt_out_states
   *   The currently opted-out states.
   * @param array $prefix_map
   *   The current prefix mapping.
   */
  private function buildWorkflowGroup(array &$form_group, string $workflow_id, $workflow_label, array $states, array $opt_out_states, array $prefix_map): void {
    $form_group[$workflow_id] = [
      '#type' => 'details',
      '#title' => $this->t('@label Workflow', ['@label' => $workflow_label]),
      '#open' => TRUE,
    ];

    $form_group[$workflow_id]['states'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('State'),
        $this->t('Opt-Out of Cascading Updates<br><small>If checked, changing a parent will NOT update this item\'s URL.</small>'),
        $this->t('URL Prefix<br><small>Prepend this word to the alias (e.g. "archived"). Leave blank for none.</small>'),
      ],
    ];

    foreach ($states as $state_id => $state) {
      $unique_state_id = "{$workflow_id}__{$state_id}";

      // Determine the state label (supports Workflow state objects and strings).
      $label = (is_object($state) && method_exists($state, 'label')) ? $state->label() : (string) $state;

      $form_group[$workflow_id]['states'][$unique_state_id] = [
        'state_label' => [
          '#markup' => '<strong>' . $label . '</strong>',
        ],
        'opt_out' => [
          '#type' => 'checkbox',
          '#default_value' => in_array($unique_state_id, $opt_out_states, TRUE),
        ],
        'prefix' => [
          '#type' => 'textfield',
          '#size' => 20,
          '#default_value' => $prefix_map[$unique_state_id] ?? '',
          '#pattern' => '^[a-z0-9-]+$',
          '#attributes' => [
            'title' => $this->t('Only lowercase letters, numbers, and hyphens are allowed.'),
          ],
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $workflows_input = $form_state->getValue('workflows');

    if (is_array($workflows_input)) {
      foreach ($workflows_input as $workflow_id => $group) {
        if (!empty($group['states']) && is_array($group['states'])) {
          foreach ($group['states'] as $state_id => $values) {
            $prefix = $values['prefix'];
            if (!empty($prefix) && !preg_match('/^[a-z0-9-]+$/', $prefix)) {
              $form_state->setErrorByName("workflows][$workflow_id][states][$state_id][prefix", $this->t('The prefix "%prefix" is invalid. Only lowercase letters, numbers, and hyphens are allowed.', ['%prefix' => $prefix]));
            }
          }
        }
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $opt_out_states = [];
    $state_prefixes = [];
    $workflows_input = $form_state->getValue('workflows');

    // Collapse the tabular data back into our schema arrays.
    if (is_array($workflows_input)) {
      foreach ($workflows_input as $group) {
        if (!empty($group['states']) && is_array($group['states'])) {
          foreach ($group['states'] as $state_id => $values) {
            // Process Opt-Out Checkbox.
            if (!empty($values['opt_out'])) {
              $opt_out_states[] = $state_id;
            }

            // Process Prefix Textfield.
            $prefix = trim($values['prefix']);
            if (!empty($prefix)) {
              // We store it as a sequence of mappings to adhere to strictly-typed config schemas.
              $state_prefixes[] = [
                'state_id' => $state_id,
                'prefix' => $prefix,
              ];
            }
          }
        }
      }
    }

    $this->config('leap_smartpaths.settings')
      ->set('opt_out_states', array_values($opt_out_states))
      ->set('state_prefixes', array_values($state_prefixes))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
