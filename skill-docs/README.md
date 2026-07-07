# Oxygen HTML Converter Skill Docs

This folder is the handoff memory for continuing the Oxygen 6 native theming/import workflow.
It documents the current implementation, Oxygen 6 patterns learned during the Maximus import, QA gates, and the remaining product hardening after the current 10/10 Maximus workflow pass.

## Current Result

The Maximus site build remains the main custom proof example. It is not the generic acceptance contract for every Core import.

For generic Core completion, use the stable fixture index, local fixture audit, live gate, visual gate, and release checks documented in `quality-gates.md` and `knowledge/KBAI`.

The Maximus proof is not only an HTML-to-Oxygen import. It is a repeatable Oxygen-native design kit build:

- 7 WordPress pages imported from Maximus fixtures.
- 1 Oxygen Header.
- 1 Oxygen Footer.
- 1 Oxygen Template with a Template Content Area.
- 24 Oxygen Blocks/components.
- 20 reusable section blocks extracted from fixture sections.
- 20 real `OxygenElements\Component` instances placed back into the 7 pages.
- 166 editable text properties wired through Oxygen Component `targets`/`properties`.
- 44 semantic selectors in the `Maximus Design System` selector collection.
- 245 selectors total.
- 27 variables.
- 20 palette colors.
- 5 global style assets.
- 0 `HtmlCode`, `CssCode`, or `JavaScriptCode` blocks in imported Oxygen documents.
- 100% native coverage in the generated import report.

Latest verified report at the time this file was written:

```text
artifacts/site-build/maximus-site-build-20260519-051234.json
```

Main runnable command:

```powershell
npm run build:site:maximus
```

Current Core QA commands from the plugin root:

```powershell
npm run check
npm run test:fixtures:local
npm run sync:docker
npm run test:live
npm run test:visual
```

## Document Map

- [oxygen6-native-patterns.md](oxygen6-native-patterns.md) - official Oxygen 6 concepts and how they map to this importer.
- [current-architecture.md](current-architecture.md) - exact implementation architecture and important code locations.
- [component-properties-research.md](component-properties-research.md) - observed Oxygen 6 Component instance and Component Properties serialization.
- [generic-site-kit-workflow.md](generic-site-kit-workflow.md) - product handoff for applying the Maximus-proven workflow to another brand/site.
- [oxygen-skill-context-audit.md](oxygen-skill-context-audit.md) - completeness audit for turning the current Oxygen 6 knowledge into a portable Codex skill.
- [maximus-implementation-log.md](maximus-implementation-log.md) - chronological process log and decisions.
- [quality-gates.md](quality-gates.md) - required checks before claiming the result is good.
- [remaining-to-10-10.md](remaining-to-10-10.md) - honest gap analysis for a full 10/10 product.
- [next-chat-runbook.md](next-chat-runbook.md) - start here when a new chat resumes the work.

## Official Oxygen References

Use official Oxygen documentation as primary context:

- Classes: https://oxygenbuilder.com/documentation/design/classes/
- Components: https://oxygenbuilder.com/documentation/design/components/
- Variables: https://oxygenbuilder.com/documentation/design/variables/
- Creating Templates: https://oxygenbuilder.com/documentation/templating/template-basics/
- Applying Templates: https://oxygenbuilder.com/documentation/templating/applying-templates/
- Template Terminology: https://oxygenbuilder.com/documentation/templating/applying-templates/template-terminology/
- Templating Conditions: https://oxygenbuilder.com/documentation/templating/templating-conditions/
- Template Content Area: https://oxygenbuilder.com/documentation/reference/elements/dynamic/template-content-area/
- Component element: https://oxygenbuilder.com/documentation/reference/elements/advanced/component/

## Core Principle

The goal is not to preserve source HTML as a frozen artifact.
The goal is to produce an Oxygen 6 native design kit that is:

- editable through Oxygen controls,
- organized around reusable classes,
- split into templates, headers, footers, pages, and components correctly,
- visually close to source by default,
- easy to restyle globally,
- repeatable from AI-generated HTML fixtures.

The current preferred shape is:

```text
AI/Figma/Stitch HTML
  -> Oxygen-native page import
  -> reusable section `oxygen_block` components
  -> page sections replaced with `OxygenElements\Component` instances
  -> editable text exposed as Component Properties
  -> selectors/variables/global settings persisted once
  -> frontend and Builder verified
```
