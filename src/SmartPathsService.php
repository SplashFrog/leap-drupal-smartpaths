<?php

declare(strict_types=1);

namespace Drupal\leap_smartpaths;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\pathauto\AliasCleanerInterface;
use Drupal\pathauto\AliasUniquifierInterface;

/**
 * Provides functionality for recursive URL construction and alias updates.
 *
 * This service is the engine behind the Smart Paths ecosystem. It traverses
 * the entity reference chain to build nested URLs, handles optional path
 * overrides, and recursively pushes alias updates down to child nodes when
 * a parent node is moved.
 */
final class SmartPathsService {

  /**
   * Name of the field to check for parent page references.
   */
  private const string PARENT_FIELD = 'field_parent_content';

  /**
   * Name of the field to check for optional short-path slugs.
   */
  private const string OPTIONAL_PATH_FIELD = 'field_optional_path';

  /**
   * Threshold for switching between synchronous and Batch API updates.
   */
  private const int BATCH_THRESHOLD = 100;

  /**
   * Array of node IDs to prevent infinite recursion loops during traversal.
   *
   * @var int[]
   */
  private array $processedNodes = [];

  /**
   * Constructs a new SmartPathsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\pathauto\AliasCleanerInterface $aliasCleaner
   *   The Pathauto string cleaner.
   * @param \Drupal\pathauto\AliasUniquifierInterface $aliasUniquifier
   *   The Pathauto uniqueness enforcer.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheInvalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasCleanerInterface $aliasCleaner,
    private readonly AliasUniquifierInterface $aliasUniquifier,
    private readonly RendererInterface $renderer,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CacheTagsInvalidatorInterface $cacheInvalidator,
  ) {}

  /**
   * Helper: Determines the unique state identifier for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to evaluate.
   *
   * @return string
   *   The machine name of the state (e.g., 'editorial__draft' or 'core__published').
   */
  public function getEntityStateIdentifier(EntityInterface $entity): string {
    $current_state = '';

    // Check if the entity is actively using a moderation workflow.
    if ($entity->hasField('moderation_state') && !$entity->get('moderation_state')->isEmpty()) {
      $raw_state = $entity->get('moderation_state')->value;

      if ($this->moduleHandler->moduleExists('content_moderation')) {
        /** @var \Drupal\content_moderation\ModerationInformationInterface $mod_info */
        $mod_info = \Drupal::service('content_moderation.moderation_information');
        $workflow = $mod_info->getWorkflowForEntity($entity);
        if ($workflow) {
          $current_state = $workflow->id() . '__' . $raw_state;
        }
      }
    }

    // Fallback: If no workflow applies, evaluate the core boolean status.
    if (empty($current_state) && method_exists($entity, 'isPublished')) {
      $current_state = $entity->isPublished() ? 'core__published' : 'core__unpublished';
    }

    return $current_state;
  }

  /**
   * Evaluates whether a given entity's state is flagged as "opt-out".
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to evaluate.
   *
   * @return bool
   *   TRUE if the state is opted-out, FALSE otherwise.
   */
  public function isStateOptedOut(EntityInterface $entity): bool {
    $opt_out_states = $this->configFactory->get('leap_smartpaths.settings')->get('opt_out_states') ?? [];
    $current_state = $this->getEntityStateIdentifier($entity);

    return in_array($current_state, $opt_out_states, TRUE);
  }

  /**
   * Builds the individual page title slug using the optional field or node title.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param array $options
   *   Additional options for the Pathauto cleaner.
   *
   * @return string
   *   The cleaned string slug.
   */
  public function buildPageTitle(NodeInterface $node, array $options = []): string {
    $node_title = $node->label() ?? '';
    $optional_text = '';

    if ($node->hasField(self::OPTIONAL_PATH_FIELD) && !$node->get(self::OPTIONAL_PATH_FIELD)->isEmpty()) {
      $optional_text = $node->get(self::OPTIONAL_PATH_FIELD)->value;
    }

    return $this->aliasCleaner->cleanString($optional_text ?: $node_title, $options);
  }

  /**
   * Recursively traverses up the entity reference chain to build the full prefix path.
   *
   * @param int|null $node_id
   *   Optional: The node ID to start traversal from.
   * @param \Drupal\node\NodeInterface|null $node
   *   Optional: The loaded node to start traversal from.
   * @param array $options
   *   Additional options for Pathauto.
   *
   * @return string
   *   The full, nested parent URL prefix (e.g. 'services/consulting').
   */
  public function buildParentPagePath(?int $node_id = NULL, ?NodeInterface $node = NULL, array $options = []): string {
    if ($node_id === NULL && $node === NULL) {
      return '';
    }

    if ($node === NULL) {
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    }

    if (!$node instanceof NodeInterface || !$node->hasField(self::PARENT_FIELD) || $node->get(self::PARENT_FIELD)->isEmpty()) {
      return '';
    }

    $parent_alias_path = '';
    $this->processedNodes = [];

    $parent_id = (int) $node->get(self::PARENT_FIELD)->target_id;
    $this->processedNodes[] = $parent_id;

    $do_loop = TRUE;
    while ($do_loop) {
      $parent_data = $this->getParentPageData($parent_id, $options);

      if (!$parent_data['status']) {
        $do_loop = FALSE;
        continue;
      }

      $parent_alias_path = $parent_alias_path ? $parent_data['path'] . '/' . $parent_alias_path : $parent_data['path'];

      if (empty($parent_data['id']) || in_array($parent_data['id'], $this->processedNodes, TRUE)) {
        $do_loop = FALSE;
        continue;
      }

      $parent_id = (int) $parent_data['id'];
      $this->processedNodes[] = $parent_id;
    }

    return $parent_alias_path;
  }

  /**
   * Helper function to get a single cleaned URL segment and parent ID.
   *
   * Optimized with static memory caching to prevent redundant DB lookups.
   */
  private function getParentPageData(int $node_id, array $options = []): array {
    $cache = &drupal_static(__METHOD__, []);
    if (isset($cache[$node_id])) {
      return $cache[$node_id];
    }

    $data = [
      'status' => FALSE,
      'path' => '',
      'id' => NULL,
    ];

    $parent_node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$parent_node instanceof NodeInterface) {
      $cache[$node_id] = $data;
      return $data;
    }

    $data['status'] = TRUE;
    $data['path'] = $this->buildPageTitle($parent_node, $options);

    if ($parent_node->hasField(self::PARENT_FIELD) && !$parent_node->get(self::PARENT_FIELD)->isEmpty()) {
      $data['id'] = $parent_node->get(self::PARENT_FIELD)->target_id;
    }

    $cache[$node_id] = $data;
    return $data;
  }

  /**
   * Primary entry point to mass-update all children of a node recursively.
   *
   * Uses a hybrid model: synchronous for small updates (<100) and Batch API
   * for enterprise-scale updates.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The parent node that was just updated.
   */
  public function updateChildrenAliases(NodeInterface $node): void {
    $descendant_ids = [];
    $this->findAllDescendantIds((int) $node->id(), $descendant_ids);

    $count = count($descendant_ids);
    if ($count === 0) {
      return;
    }

    if ($count <= self::BATCH_THRESHOLD) {
      $this->executeSynchronousUpdate($descendant_ids);
    }
    else {
      $this->executeBatchUpdate($descendant_ids);
    }
  }

  /**
   * Recursively finds all descendant node IDs (children, grandchildren, etc.).
   */
  private function findAllDescendantIds(int $parent_id, array &$descendants): void {
    $child_ids = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::PARENT_FIELD, $parent_id)
      ->execute();

    if (!empty($child_ids)) {
      foreach ($child_ids as $id) {
        $id = (int) $id;
        if (!in_array($id, $descendants, TRUE)) {
          $descendants[] = $id;
          $this->findAllDescendantIds($id, $descendants);
        }
      }
    }
  }

  /**
   * Updates a small set of descendants synchronously.
   */
  private function executeSynchronousUpdate(array $ids): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $nodes = $node_storage->loadMultiple($ids);

    foreach ($nodes as $node) {
      // Check for individual opt-out.
      if ($this->isStateOptedOut($node)) {
        continue;
      }

      // Only update if Pathauto is enabled for this child.
      if ($node->hasField('path') && $node->get('path')->pathauto) {
        $this->updateSingleChildAlias($node);
      }
    }
  }

  /**
   * Handsoff a large set of descendants to the Drupal Batch API.
   */
  private function executeBatchUpdate(array $ids): void {
    $chunks = array_chunk($ids, 50);
    $operations = [];
    foreach ($chunks as $chunk) {
      $operations[] = [
        '\Drupal\leap_smartpaths\Batch\SmartPathsBatchHandler::processChunk',
        [$chunk],
      ];
    }

    $batch = [
      'title' => t('Updating Child URL Aliases...'),
      'operations' => $operations,
      'finished' => '\Drupal\leap_smartpaths\Batch\SmartPathsBatchHandler::finished',
    ];

    batch_set($batch);
  }

  /**
   * Re-calculates and saves a single child's path alias.
   */
  public function updateSingleChildAlias(NodeInterface $node): void {
    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');
    $aliases = $path_alias_storage->loadByProperties([
      'path' => '/node/' . $node->id(),
    ]);

    if (empty($aliases)) {
      return;
    }

    $parent_path = $this->buildParentPagePath(NULL, $node);
    if (empty($parent_path)) {
      return;
    }

    $clean_title = $this->buildPageTitle($node);
    $new_alias = '/' . $parent_path . '/' . $clean_title;

    $source = '/node/' . $node->id();
    $langcode = $node->language()->getId();
    $this->aliasUniquifier->uniquify($new_alias, $source, $langcode);

    foreach ($aliases as $alias_entity) {
      $alias_entity->setAlias($new_alias);
      $alias_entity->save();
      Cache::invalidateTags($node->getCacheTags());
    }
  }

  /**
   * Applies the configured state prefix to an alias, stripping old prefixes.
   */
  public function applyStatePrefix(string $alias, EntityInterface $entity): string {
    $config_prefixes = $this->configFactory->get('leap_smartpaths.settings')->get('state_prefixes') ?? [];

    if (empty($config_prefixes)) {
      return $alias;
    }

    $map = [];
    $all_slugs = [];
    foreach ($config_prefixes as $item) {
      if (isset($item['state_id'], $item['prefix'])) {
        $map[$item['state_id']] = $item['prefix'];
        $all_slugs[] = preg_quote($item['prefix'], '#');
      }
    }

    $clean_alias = $alias;

    if (!empty($all_slugs)) {
      $regex = '#^/(' . implode('|', $all_slugs) . ')(/|$)#';
      $clean_alias = preg_replace($regex, '/', $alias);
    }

    $clean_alias = '/' . ltrim($clean_alias, '/');

    $current_state = $this->getEntityStateIdentifier($entity);
    if (isset($map[$current_state]) && !empty($map[$current_state])) {
      return '/' . $map[$current_state] . $clean_alias;
    }

    return $clean_alias;
  }

  /**
   * Uniquifies an alias for a specific entity.
   */
  public function uniquifyAlias(string &$alias, EntityInterface $entity): void {
    $source = '/' . $entity->getEntityTypeId() . '/' . $entity->id();
    $langcode = $entity->language()->getId();
    $this->aliasUniquifier->uniquify($alias, $source, $langcode);
  }

}
