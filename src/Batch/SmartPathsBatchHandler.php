<?php

declare(strict_types=1);

namespace Drupal\leap_smartpaths\Batch;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Handles Batch API operations for mass URL alias updates.
 */
final class SmartPathsBatchHandler {

  /**
   * Processes a chunk of node IDs to update their aliases.
   *
   * @param int[] $ids
   *   The array of node IDs to process in this chunk.
   * @param array $context
   *   The batch context array.
   */
  public static function processChunk(array $ids, array &$context): void {
    /** @var \Drupal\leap_smartpaths\SmartPathsService $service */
    $service = \Drupal::service('leap_smartpaths.logic');
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    if (!isset($context['results']['updated'])) {
      $context['results']['updated'] = 0;
      $context['results']['skipped'] = 0;
    }

    $nodes = $node_storage->loadMultiple($ids);

    foreach ($nodes as $node) {
      // Check if this specific node should be skipped based on its state.
      if ($service->isStateOptedOut($node)) {
        $context['results']['skipped']++;
        continue;
      }

      // Only update if Pathauto is enabled for this node.
      if ($node->hasField('path') && $node->get('path')->pathauto) {
        $service->updateSingleChildAlias($node);
        $context['results']['updated']++;
      }
      else {
        $context['results']['skipped']++;
      }
    }

    $context['message'] = (string) new TranslatableMarkup('Updated @count aliases...', ['@count' => $context['results']['updated']]);
  }

  /**
   * Finished callback for the batch process.
   *
   * @param bool $success
   *   Indicates if the batch completed successfully.
   * @param array $results
   *   The results array from the batch context.
   * @param array $operations
   *   The operations that were performed.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if ($success) {
      $updated = $results['updated'] ?? 0;
      $skipped = $results['skipped'] ?? 0;
      $messenger->addStatus(new TranslatableMarkup('Successfully updated @updated child URL aliases (@skipped skipped).', [
        '@updated' => $updated,
        '@skipped' => $skipped,
      ]));
    }
    else {
      $messenger->addError(new TranslatableMarkup('An error occurred during the URL alias update process.'));
    }
  }

}
