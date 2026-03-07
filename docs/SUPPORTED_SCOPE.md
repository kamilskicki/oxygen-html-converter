# Supported Scope

This document describes what the public `Core` plugin is meant to handle reliably today, and what it does not yet promise.

## Product Standard

For supported inputs, the expected result is:

- import succeeds without corrupting the Oxygen document
- the imported page opens in Oxygen Builder without validation errors
- the user can edit, save, and reopen the page in Builder
- the frontend remains materially close to the source HTML for a human reviewer

## Supported Inputs

Core is currently aimed at:

- single-page marketing and landing-page HTML
- HTML with inline styles
- utility-first CSS markup, especially Tailwind-style classes
- WindPress-assisted Tailwind rendering
- non-module inline scripts needed for page behavior
- simple preserved interactions such as:
  - menu toggles
  - anchor scroll
  - reveal-on-scroll
  - counters
  - straightforward inline event handlers

## Supported Output Guarantees

Core should provide:

- native Oxygen elements where supported
- builder-safe document serialization
- preserved IDs, classes, and relevant attributes
- safe degradation for unsupported constructs
- no dependency on `Pro` for import or editability

## Current Non-Goals

Core does not yet promise:

- perfect conversion of every arbitrary HTML document on the public web
- full migration of framework apps
- module bundler support
- SPA routing semantics
- React, Vue, or similar app-runtime parity
- complete external stylesheet ingestion and rule reconstruction
- Web Components parity beyond safe preservation

## Practical Reading

If a page is mostly marketing HTML, utility classes, inline styles, and lightweight scripts, it is inside the intended support envelope.

If a page depends on a complex external CSS architecture, framework runtime, or application state system, it is outside the current Core guarantee even if partial conversion works.
