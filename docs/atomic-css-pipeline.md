# Atomic CSS Pipeline Analysis — Elementor 4.1.3

## Pipeline Architecture

There are TWO independent CSS rendering paths for Atomic styles:

### Path A: Global Classes (Kit-defined)
1. `elementor/document/after_save` → `Global_Classes_Relations::set_styles_for_post()` → writes `_elementor_used_global_class` meta
2. `elementor/atomic-widgets/styles/register` → `Atomic_Global_Styles::register_styles()` (priority 20)
3. Calls `Global_Classes_Repository::get_by_ids()` → reads `e_global_class` CPT posts
4. Filters through `all_labels()` (from Kit `_elementor_global_classes` meta) — ONLY global classes pass

### Path B: Local Styles (element-defined)
1. `elementor/atomic-widgets/styles/register` → `Atomic_Widget_Styles::register_styles()` (priority 30)
2. Calls `parse_post_styles()` → `traverse_post_elements()` → `parse_element_style()`
3. Gate: `Utils::is_atomic($element_instance)` — element must be registered as `Atomic_Element_Base` or `Atomic_Widget_Base`
4. Returns `$element_data['styles']` directly

## Where the Break Happens

### Break Point 1: `get_element_instance()` returns null
`Atomic_Elements_Utils::get_element_instance()` calls:
```php
$widget = Plugin::instance()->widgets_manager->get_widget_types($element_type);
$element = Plugin::instance()->elements_manager->get_element_types($element_type);
return $widget ?? $element;
```
If Elementor 4.1.3 doesn't register `e-flexbox`/`e-heading`/etc. as atomic elements yet (beta/experimental), this returns null. Then `Utils::is_atomic(null)` → false → `parse_element_style()` → `return []`.

**This would silence ALL local styles.**

### Break Point 2: `traverse_post_elements()` gets empty data
`$document->get_elements_data()` might process through the document type pipeline. A `wp-page` document type may not expose the `_elementor_data` tree in the format that `iterate_data()` expects for Atomic elements.

### Break Point 3: `enqueue_styles()` never fires
If `elementor/post/render` doesn't fire for converted pages, `$this->post_ids` stays empty, and the `empty($this->post_ids)` check at the top of `enqueue_styles()` causes an early return.

## Recommended Fix: Custom Local-Style CSS Renderer

The most robust approach is to bypass Elementor's incomplete pipeline and render local styles directly from the element tree:

1. **Walk the V4 element tree** 
2. **Collect all `styles` maps** from each element
3. **Feed to `Styles_Renderer::render()`** — which already handles the style format we produce
4. **Output as inline `<style>` block** in `wp_head` or `elementor/frontend/after_enqueue_styles`

Since `Styles_Renderer::render()` already handles the exact format we produce (type=class, id, variants with props/meta), we only need the collection + injection layer.

### Implementation Plan
1. Create `Local_Styles_Renderer` class in novamira-adrianv2
2. Hook into `wp_head` or `elementor/frontend/after_enqueue_styles` to inject inline CSS
3. Walk `_elementor_data` via `Plugin::$instance->db->iterate_data()` to collect styles
4. Call `Styles_Renderer::render()` to generate CSS
5. Output as `<style id="novamira-local-styles">...</style>`
