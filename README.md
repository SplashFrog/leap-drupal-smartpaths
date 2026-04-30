# Splash Frog - LEAP Smart Paths

**Enterprise state-aware hierarchical URL alias management for Drupal 11.**

The LEAP Smart Paths module solves the massive headache of managing nested URL aliases (e.g., `/services/consulting/enterprise`) and moderation state visibility (e.g., `/archived/services/...`) at an enterprise scale.

It replaces the brittle Drupal core Menu routing system with a robust Entity Reference chain, allowing content to inherently know its place in the site architecture.

---

## ✨ Key Features

- **Dynamic Nesting:** Introduces the `[node:parent_path]` Pathauto token. It recursively traverses up a node's "Parent Page" entity reference chain to build a full URL prefix dynamically.
- **Cascading Updates:** If a parent node is renamed or moved, the `SmartPathsService` automatically cascades the URL update down to all of its children, grandchildren, and great-grandchildren.
- **Configurable State Prefixing:** (The URL Decorator) Site builders can configure specific moderation workflow states to automatically prepend URL slugs. For example, moving a page to the `Archived` state can automatically change its URL from `/services` to `/archived/services`.
- **Intelligent Opt-Outs:** (The Cascade Guard) Site builders can designate specific workflow states to "Opt-Out" of cascading updates. If a child node is in an opted-out state (like `Trash`), it will safely ignore structural changes made to its parent.
- **Entity-Agnostic Prefixing:** The state prefixing engine works on **any** content entity that uses Pathauto and Content Moderation, including Nodes, Media, and Taxonomy Terms.
- **Enterprise Performance Engine:** Capable of handling massive alias cascades (e.g., a parent with 5,000+ children) without causing PHP timeouts.
  - **Hybrid Dispatch:** Small updates (<100 nodes) run synchronously. Large updates automatically trigger the **Drupal Batch API** to provide visual progress bars to the editor.
  - **Flat Discovery:** Recursively flattens the descendant tree up-front to prevent infinite save loops.
  - **Static Caching:** Uses `drupal_static` memory caching during tree traversal to eliminate 99% of redundant database queries when processing large batches.

---

## 🛠️ Requirements

- **Drupal:** ^11.3
- **PHP:** >=8.3
- **Modules:** `pathauto`, `token`, `views`

---

## 🚀 Installation & Setup

1. **Enable the Module:**
   ```bash
   drush en leap_smartpaths
   ```
2. **Apply the Fields Recipe:**
   This module relies on specific fields (`field_parent_content` and `field_optional_path`) to construct the hierarchy. Apply the associated configuration recipe to attach these to your desired content types.
   ```bash
   drush recipe modules/contrib/leap_smartpaths/recipes/leap_smartpaths
   ```
   *Note: If you are using the LEAP Starter ecosystem, ensure the `leap_content` recipe is applied first.*

3. **Configure Workflow Rules:**
   Navigate to `/admin/config/search/leap-smartpaths` to configure your State Prefixes and Opt-Out rules.

---

## 🧩 How It Works

### The Dual-Configuration Engine
The module strictly separates the concept of "Cascading Inheritance" from "URL Decoration", allowing them to work together or entirely independently.

1. **Opt-Out (Cascading Inheritance):**
   When a parent's URL changes, the system looks at all children. If a child is in an "Opt-Out" state, it skips that child.
2. **Prefixing (URL Decoration):**
   When an entity is saved (manually or via Pathauto), the system performs a **Surgical Strip**. It uses a strict regex to strip any known prefixes from the very beginning of the alias (preventing duplicate `/trash/archived/node` structures), and then prepends the prefix specific to the entity's *current* state.

### The Tokens
- `[node:parent_path]`: Recursively builds the URL prefix. **Crucial:** Ensure `parent_path` is added to your Pathauto 'Safe tokens' list in configuration, otherwise Drupal will convert the forward slashes `/` into hyphens `-`. (Our recipe handles this automatically).
- `[node:optional_title]`: Uses the `field_optional_path` if provided, otherwise falls back to the standard node title. This allows editors to have long page titles (H1s) but short URL slugs.

---

## 👨‍💻 Developer API

Developers can access the URL generation and mass-update logic directly via the strictly-typed service:

```php
/** @var \Drupal\leap_smartpaths\SmartPathsService $smart_paths */
$smart_paths = \Drupal::service('leap_smartpaths.logic');

// Apply a state prefix to a raw string manually:
$decorated_alias = $smart_paths->applyStatePrefix('/my-raw-alias', $entity);
```

---

## 🛡️ License
This module is available under the standard Drupal General Public License (GPL) version 2 or later.
