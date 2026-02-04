# Oxygen HTML Converter - v1.0 Release Roadmap

## Document Info
- **Version**: 1.0.0 Production Release Plan
- **Generated**: February 1, 2026
- **Confidence Level**: MEDIUM (see notes at end)

---

## 1. Current State Assessment

### ✅ What Works Now (Functional)

| Feature | Status | Notes |
|---------|--------|-------|
| Basic HTML → Oxygen element mapping | ✅ Stable | 30+ HTML tags mapped to Oxygen types |
| CSS class preservation | ✅ Stable | Classes stored in `settings.advanced.classes` |
| HTML ID preservation | ✅ Stable | IDs preserved in `settings.advanced.id` |
| Custom attributes (data-*, aria-*) | ✅ Stable | Preserved in `settings.advanced.attributes` |
| Inline style extraction | ✅ Working | 50+ CSS properties mapped to Oxygen |
| Event handler → Interactions | ✅ Working | onclick, onmouseenter, etc. → Oxygen Interactions |
| JavaScript transformation | ✅ Working | Functions transformed to `window.*` assignments |
| Argument passing for interactions | ✅ Working | `func(arg)` stores args in data attributes |
| Tailwind grid mapping | ✅ Working | Grid classes → native Oxygen grid properties |
| Admin interface | ✅ Working | Full conversion UI with preview |
| Builder integration | ✅ Working | Paste interception in Oxygen Builder |
| Conversion reports | ✅ Working | Stats, warnings, errors tracked |
| Icon library detection | ✅ Working | Lucide, Font Awesome, etc. detected |
| Framework detection | ✅ Partial | Alpine.js, HTMX detected (not converted) |

### 🔴 Critical Issues (Must Fix for v1.0)

| Issue | Severity | Location | Impact |
|-------|----------|----------|--------|
| PHP 8.0 union type (`int\|false`) | 🔴 CRITICAL | `JavaScriptTransformer.php:178` | Fatal error on PHP 7.4 |
| `str_starts_with()` usage | 🔴 CRITICAL | `FrameworkDetector.php:53,67,94`, `InteractionDetector.php:102` | Fatal error on PHP 7.4 |
| Wrong Oxygen detection | 🔴 CRITICAL | `oxygen-html-converter.php:45-50` | Plugin won't load with Oxygen 6 RC1 |
| Wrong WindPress detection | 🔴 CRITICAL | `EnvironmentService.php:21-38` | Wrong class handling strategy |
| Global `get_option()` function | 🔴 CRITICAL | `EnvironmentService.php:5-9` | PHP fatal error |
| Debug `error_log()` calls | 🔴 CRITICAL | `TreeBuilder.php:412,449,514` | Pollutes logs in production |
| AJAX batch validation | 🟠 HIGH | `Ajax.php:133` | Potential security/bug issue |

### 🟠 Known Limitations (Acceptable for v1.0 with documentation)

| Limitation | Severity | User Impact |
|------------|----------|-------------|
| Regex-based JavaScript parsing | 🟠 HIGH | Complex JS patterns may fail |
| No ES6 module support | 🟠 HIGH | `import/export` not handled |
| Limited event handler patterns | 🟠 HIGH | `onclick="return false"` not supported |
| No external CSS extraction | 🟠 HIGH | Styles from `<link>` tags ignored |
| Framework directives not converted | 🟡 MEDIUM | Alpine.js `@click` preserved but not converted |
| No batch processing UI | 🟡 MEDIUM | One conversion at a time |
| TreeBuilder is a "God class" | 🟡 MEDIUM | Maintenance burden, not user-facing |

### 📊 Test Coverage Status

| Component | Coverage | Status |
|-----------|----------|--------|
| `TreeBuilder` | ~70% | ✅ Acceptable |
| `ElementMapper` | ~80% | ✅ Good |
| `HtmlParser` | ~60% | ⚠️ Needs improvement |
| `StyleExtractor` | ~50% | ⚠️ Needs improvement |
| `JavaScriptTransformer` | ~75% | ✅ Good |
| `InteractionDetector` | ~70% | ✅ Acceptable |
| Services (all) | ~65% | ⚠️ Needs improvement |

---

## 2. Atomic Task Breakdown

### CORE (Must Have for v1.0)

| ID | Task | Description | Effort (hrs) | Dependencies |
|----|------|-------------|--------------|--------------|
| C1 | PHP 7.4 Compatibility | Fix union types, add polyfills for `str_starts_with()` | 1 | None |
| C2 | Fix Oxygen Detection | Replace Breakdance checks with proper Oxygen 6 detection | 0.5 | None |
| C3 | Fix WindPress Detection | Correct plugin detection logic, remove global function | 0.5 | None |
| C4 | Remove Debug Logging | Comment out all `error_log()` calls | 0.25 | None |
| C5 | AJAX Security Fix | Add input validation for batch processing | 0.5 | None |
| C6 | Add Missing Tests | Create `HtmlParserTest`, `ElementMapperTest`, `StyleExtractorTest` | 4 | None |
| C7 | Integration Test Directory | Create `tests/Integration` folder | 0.25 | None |
| C8 | Error Handling | Add try/catch in TreeBuilder, graceful degradation | 2 | C1-C5 |
| C9 | Input Validation | Validate HTML input, size limits, XSS prevention | 2 | None |
| C10 | Documentation | Update README, ROADMAP, inline docs | 3 | All above |

**CORE Total: ~14 hours**

### POLISH (Should Have for v1.0)

| ID | Task | Description | Effort (hrs) | Dependencies |
|----|------|-------------|--------------|--------------|
| P1 | Dependency Injection | Make TreeBuilder services injectable | 3 | None |
| P2 | Service Interfaces | Create contracts for Parser, Detector, Transformer | 2 | P1 |
| P3 | TreeBuilder Refactor | Extract NodeConverter, CssExtractor, ScriptProcessor | 6 | P1, P2 |
| P4 | CSS @media Support | Handle media queries in `<style>` tags | 4 | None |
| P5 | Tailwind Property Mapping | Map more Tailwind classes to Oxygen properties | 6 | None |
| P6 | Better Error Messages | User-friendly conversion failure messages | 2 | C8 |
| P7 | Admin UI Polish | Better preview, copy feedback, loading states | 3 | None |

**POLISH Total: ~26 hours**

### MARKETING (Nice to Have for v1.0)

| ID | Task | Description | Effort (hrs) | Dependencies |
|----|------|-------------|--------------|--------------|
| M1 | Landing Page | GitHub README polish, screenshots, GIFs | 3 | All CORE |
| M2 | Demo Video | 2-3 minute walkthrough video | 4 | All CORE |
| M3 | Example Templates | 5-10 sample HTML templates | 4 | None |
| M4 | Changelog | Proper v1.0.0 changelog | 1 | None |

**MARKETING Total: ~12 hours**

### OPS (Release Infrastructure)

| ID | Task | Description | Effort (hrs) | Dependencies |
|----|------|-------------|--------------|--------------|
| O1 | CI/CD Setup | GitHub Actions for tests, PHP 7.4/8.x matrix | 3 | None |
| O2 | Release Script | Automated zip creation, version bumping | 2 | None |
| O3 | WordPress.org Prep | SVN setup, assets, readme.txt | 4 | All CORE |
| O4 | Version Tagging | Git tag v1.0.0, GitHub release | 0.5 | All CORE |

**OPS Total: ~9.5 hours**

---

## 3. Critical Path Analysis

### Dependency Graph

```
C1 (PHP 7.4) ──────────────────────────────────────┐
C2 (Oxygen Detection) ──────────────────────────────┤
C3 (WindPress Detection) ───────────────────────────┤──> C8 (Error Handling) ──> C10 (Docs)
C4 (Debug Removal) ─────────────────────────────────┤
C5 (AJAX Security) ─────────────────────────────────┘

P1 (DI) ──> P2 (Interfaces) ──> P3 (TreeBuilder Refactor)

C8 ──> P6 (Better Errors)
C10 ──> M1 (Landing Page) ──> M2 (Demo Video)

All CORE ──> O3 (WP.org) ──> O4 (Release)
```

### Longest Dependency Chain

```
C1 ──> C8 ──> C10 ──> M1 ──> M2
  (1h)  (2h)   (3h)   (3h)  (4h)
  
Total: 13 hours (critical path for full release)
```

### Bottlenecks

1. **C8 (Error Handling)** - Blocks documentation and polish
2. **P3 (TreeBuilder Refactor)** - Large task, not strictly required for v1.0
3. **O3 (WordPress.org)** - Requires all core to be stable

---

## 4. Prioritization Framework

### MoSCoW Analysis

#### MUST Have (v1.0 Blockers)
- ✅ All CORE tasks (C1-C10)
- ✅ Basic documentation (README, usage)
- ✅ Plugin activates without errors
- ✅ Converts basic HTML templates successfully
- ✅ No PHP fatal errors on PHP 7.4+

#### SHOULD Have (v1.0 Ideally)
- P1, P2 (Dependency Injection, Interfaces) - For maintainability
- P6, P7 (Better Errors, Admin Polish) - For UX
- M1, M4 (Landing Page, Changelog) - For adoption
- O1, O2, O4 (CI/CD, Release Script, Version Tag) - For process

#### COULD Have (Post-v1.0)
- P3 (TreeBuilder Refactor) - Technical debt
- P4, P5 (@media support, Tailwind mapping) - Features
- M2, M3 (Video, Templates) - Marketing
- O3 (WordPress.org) - Distribution

#### WON'T Have (v1.0)
- Batch processing UI
- Alpine.js → Interactions conversion
- External CSS fetching
- Live preview in admin
- Template library

### P0/P1/P2 Ranking

| Priority | Tasks | Total Hours |
|----------|-------|-------------|
| **P0** | C1-C10, M4, O4 | 18.5 hrs |
| **P1** | P1, P2, P6, P7, M1, O1, O2 | 16 hrs |
| **P2** | P3, P4, P5, M2, M3, O3 | 25.5 hrs |

---

## 5. Timeline Estimate

### Assumptions
- Kamil's availability: 10-15 hrs/week (evenings/weekends)
- No blockers from external dependencies
- Testing happens in parallel with development

### Calendar Estimates

#### Option A: Minimal v1.0 (P0 Only)
**Total Effort**: ~18.5 hours  
**Calendar Time**: 2 weeks (at 10 hrs/week)

| Week | Focus | Deliverable |
|------|-------|-------------|
| 1 | C1-C7 (Critical fixes + tests) | Plugin passes all tests |
| 2 | C8-C10, M4, O4 (Error handling + docs + release) | v1.0.0 tagged |

#### Option B: Solid v1.0 (P0 + P1)
**Total Effort**: ~34.5 hours  
**Calendar Time**: 3-4 weeks (at 10 hrs/week)

| Week | Focus | Deliverable |
|------|-------|-------------|
| 1 | C1-C7 | Core fixes done |
| 2 | C8-C10, M4, O1, O2 | Error handling + CI/CD |
| 3 | P1, P2, P6, P7 | Architecture polish |
| 4 | Buffer + O4 | Release |

#### Option C: Full v1.0 (P0 + P1 + selective P2)
**Total Effort**: ~45 hours  
**Calendar Time**: 5 weeks (at 10 hrs/week)

| Week | Focus | Deliverable |
|------|-------|-------------|
| 1 | C1-C7 | Core fixes |
| 2 | C8-C10, M4, O1-O2 | Polish + CI/CD |
| 3 | P1, P2, P3 (partial) | Architecture |
| 4 | P6, P7, M1, M3 | UX + Marketing |
| 5 | O3, O4, Buffer | WordPress.org + Release |

### Recommended: Option B (Solid v1.0)

**Rationale**:
- Fixes all critical bugs
- Adds enough polish for professional release
- Sets up CI/CD for future development
- ~3-4 weeks is realistic without burnout
- Can defer WordPress.org to v1.1

---

## 6. Sprint Structure

### Sprint 1: Stabilization (Week 1)
**Goal**: Plugin passes all tests on PHP 7.4+ with Oxygen 6

| Day | Task | Hours |
|-----|------|-------|
| 1-2 | C1: PHP 7.4 polyfills, fix union types | 1 |
| 2-3 | C2, C3: Fix Oxygen & WindPress detection | 1 |
| 3 | C4, C5: Remove debug logs, AJAX validation | 0.75 |
| 4-5 | C6, C7: Core tests + integration dir | 4.25 |
| 5 | Testing & bug fixes | 3 |

**Sprint Deliverable**: All tests passing

### Sprint 2: Hardening (Week 2)
**Goal**: Error handling, documentation, release prep

| Day | Task | Hours |
|-----|------|-------|
| 1-2 | C8: Error handling, try/catch | 2 |
| 2-3 | C9: Input validation, security | 2 |
| 3-4 | C10: Documentation updates | 3 |
| 4-5 | M4, O1, O2: Changelog, CI/CD, release script | 5.5 |
| 5 | Testing & buffer | 1.5 |

**Sprint Deliverable**: Release candidate

### Sprint 3: Polish (Week 3-4)
**Goal**: Professional UX, architecture improvements

| Day | Task | Hours |
|-----|------|-------|
| 1-2 | P1, P2: DI + Interfaces | 5 |
| 3 | P6: Better error messages | 2 |
| 4 | P7: Admin UI polish | 3 |
| 5 | M1: Landing page, screenshots | 3 |
| 6-7 | Buffer, testing, O4: Release | 3 |

**Sprint Deliverable**: v1.0.0 released

---

## 7. Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Oxygen 6 API changes | Medium | High | Monitor Oxygen RC updates, keep tests passing |
| Complex JS patterns fail | High | Medium | Document limitations, graceful fallback |
| Time overrun | Medium | Medium | Use Option B scope, defer P2 to v1.1 |
| WordPress.org rejection | Low | Low | Follow guidelines, prepare early |

---

## 8. Confidence Levels

| Estimate Category | Confidence | Notes |
|-------------------|------------|-------|
| **CORE tasks (C1-C10)** | HIGH | Clear fixes, known scope |
| **POLISH tasks (P1-P7)** | MEDIUM | Some unknowns in refactoring |
| **MARKETING (M1-M4)** | HIGH | Straightforward |
| **OPS (O1-O4)** | HIGH | Standard practices |
| **Timeline (Option B)** | MEDIUM | Assumes 10-15 hrs/week availability |
| **Overall v1.0 Success** | HIGH | Core is solid, just needs polish |

---

## 9. Success Criteria

### v1.0 Definition of Done

- [ ] All P0 tasks complete
- [ ] PHPUnit tests pass on PHP 7.4, 8.0, 8.1, 8.2
- [ ] Plugin activates cleanly with Oxygen Builder 6 RC1
- [ ] Converts 5 test templates without fatal errors
- [ ] README is accurate and complete
- [ ] Git tag `v1.0.0` exists
- [ ] GitHub release published

### Post-v1.0 Metrics

| Metric | Target |
|--------|--------|
| Static HTML conversion | 95%+ success rate |
| Tailwind templates | 85%+ success rate |
| Simple interactivity | 80%+ success rate |
| GitHub stars | 50+ (first month) |
| WordPress installs | 100+ (first month) |

---

## 10. Immediate Next Steps

1. **Today**: Fix C1-C5 (critical bugs) - 3.75 hours
2. **This Week**: Complete C6-C7 (tests) - 4.25 hours
3. **Next Week**: C8-C10 (error handling + docs) - 7 hours
4. **Week 3**: P0 polish + release - 3 hours

**Total to v1.0.0-rc1**: ~18 hours (2 weeks at relaxed pace)

---

*End of Roadmap*
