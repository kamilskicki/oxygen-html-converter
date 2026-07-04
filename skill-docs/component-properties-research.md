# Oxygen 6 Component Properties Research

This document records the observed Oxygen 6 Component serialization used by the Maximus workflow.

## Files Inspected

Inside the local `oxyconvo6-wordpress-1` container:

```text
/var/www/html/wp-content/plugins/oxygen/subplugins/oxygen-elements/elements/Component/element.php
/var/www/html/wp-content/plugins/oxygen/subplugins/oxygen-elements/elements/Component/ssr.php
/var/www/html/wp-content/plugins/oxygen/subplugins/oxygen-elements/elements/Component/component.php
/var/www/html/wp-content/plugins/oxygen/plugin/breakdance-oxygen/components.php
/var/www/html/wp-content/plugins/oxygen/plugin/render/global-blocks.php
/var/www/html/wp-content/plugins/oxygen/plugin/admin/util.php
```

## Component Element

Element slug:

```text
OxygenElements\Component
```

The content control path is:

```text
content.content.block
```

The selected component ID is:

```text
content.content.block.componentId
```

Builder URL helper:

```php
\Breakdance\Admin\get_builder_loader_url((string) $postId)
```

## Runtime Rendering

Component SSR reads:

```php
$component = $propertiesData['content']['content']['block'] ?? null;
$componentId = $propertiesData['content']['content']['block']['componentId'] ?? null;
```

When present, Oxygen renders:

```php
\Breakdance\Render\renderGlobalBlock($componentId, $repeaterItemNodeId)
```

That means a page-side Component node must carry the entire component input object, not only the ID.

## Page-Side Instance Shape

```php
[
  'id' => 100,
  'data' => [
    'type' => 'OxygenElements\\Component',
    'properties' => [
      'content' => [
        'content' => [
          'block' => [
            'componentId' => 123,
            'targets' => [
              [
                'nodeId' => 8,
                'propertyKey' => 'maximus_section_text_8',
                'controlPath' => 'content.content.text',
              ],
            ],
            'properties' => [
              'maximus_section_text_8' => 'Instance text value',
            ],
          ],
        ],
      ],
    ],
  ],
  'children' => [],
]
```

## Block-Side Editable Property Shape

The target node inside the `oxygen_block` must expose matching editable metadata:

```php
[
  'id' => 8,
  'data' => [
    'type' => 'OxygenElements\\Text',
    'properties' => [
      'content' => [
        'content' => [
          'text' => 'Default text value',
        ],
      ],
      'meta' => [
        'component' => [
          'editableProperties' => [
            [
              'enabled' => true,
              'label' => 'Section heading: Example',
              'controlPath' => 'content.content.text',
              'propertyKey' => 'maximus_section_text_8',
            ],
          ],
        ],
      ],
    ],
  ],
]
```

## Runtime Override Flow

`ComponentInputValueHolder::setCurrentComponent($component)` adds the render filter:

```text
breakdance_before_render_node
```

For each rendered node, Oxygen checks instance `targets` for matching `nodeId`.
If the target key exists in instance `properties`, Oxygen assigns that value into the node at `controlPath`.

For text:

```text
content.content.text
```

## Current Maximus Implementation

Implemented in:

```text
tests/live/build-maximus-site.php
```

Key functions:

```text
enableMaximusComponentProperties()
attachEditableTextPropertiesToTree()
componentizeImportedPages()
buildOxygenComponentInstanceNode()
```

Current scope:

- text properties only
- icon text is skipped
- standard pages componentize `maximus-main` sections
- diagnosis componentizes progress + main content groups

Current verified metrics:

```text
20 page Component instances
166 editable text properties on page instances
176 editable text markers across oxygen_block components
```

