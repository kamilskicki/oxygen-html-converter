(function ($) {
  "use strict";

  const config = window.oxyHtmlConverterAdmin || {};
  const strings = config.strings || {};
  const ui = config.ui || {};
  const presetUtils = window.OxyHtmlConverterPresets || null;
  const optionUtils = window.OxyHtmlConverterOptions || null;
  const requestClientUtils = window.OxyHtmlConverterAdminClient || null;
  const rendererUtils = window.OxyHtmlConverterAdminRenderers || null;
  const stateUtils = window.OxyHtmlConverterAdminState || null;
  const state = stateUtils ? stateUtils.createAdminState() : null;
  let isApplyingPreset = false;

  const $htmlInput = $("#oxy-html-input");
  const $previewBtn = $("#oxy-preview-btn");
  const $convertBtn = $("#oxy-convert-btn");
  const $copyBtn = $("#oxy-copy-btn");
  const $loadExampleBtn = $("#oxy-load-example-btn");
  const $preset = $("#oxy-convert-preset");
  const $wrapContainer = $("#oxy-wrap-container");
  const $includeCss = $("#oxy-include-css");
  const $inlineStyles = $("#oxy-inline-styles");
  const $safeMode = $("#oxy-safe-mode");
  const $allowExecutableCode = $("#oxy-allow-executable-code");
  const $strictNative = $("#oxy-strict-native");

  const $previewResult = $("#oxy-preview-result");
  const $previewContent = $("#oxy-preview-content");
  const $jsonResult = $("#oxy-json-result");
  const $jsonOutput = $("#oxy-json-output");
  const $jsonStatus = $(".oxy-json-status");
  const $errorResult = $("#oxy-error-result");
  const $errorContent = $("#oxy-error-content");

  const $reportSummary = $("#oxy-report-summary");
  const $auditPreserved = $("#oxy-audit-preserved");
  const $auditTransformed = $("#oxy-audit-transformed");
  const $auditStripped = $("#oxy-audit-stripped");
  const $auditFollowUp = $("#oxy-audit-follow-up");

  function setPanelHidden($el, hidden) {
    $el.prop("hidden", hidden);
  }

  function showLoading($btn) {
    $btn.addClass("oxy-loading").prop("disabled", true);
  }

  function hideLoading($btn) {
    $btn.removeClass("oxy-loading").prop("disabled", false);
  }

  function getCurrentOptions() {
    return {
      wrapInContainer: $wrapContainer.is(":checked"),
      includeCssElement: $includeCss.is(":checked"),
      inlineStyles: $inlineStyles.is(":checked"),
      safeMode: $safeMode.is(":checked"),
      allowExecutableCode:
        !$safeMode.is(":checked") &&
        !$strictNative.is(":checked") &&
        $allowExecutableCode.is(":checked"),
      strictNative: $strictNative.is(":checked"),
      debugMode: false,
    };
  }

  const requestClient =
    requestClientUtils && typeof requestClientUtils.createRequestClient === "function"
      ? requestClientUtils.createRequestClient({
          $: $,
          ajaxUrl: config.ajaxUrl,
          nonce: config.nonce,
          optionUtils: optionUtils,
        })
      : null;

  function clearResults() {
    setPanelHidden($previewResult, true);
    setPanelHidden($jsonResult, true);
    setPanelHidden($errorResult, true);
    $copyBtn.prop("disabled", true);
    if (state) {
      state.clearLastConvertedJson();
    }
    $jsonStatus.text("");
  }

  function setAuditList($target, items) {
    const list = Array.isArray(items) ? items.filter(Boolean) : [];
    $target.empty();

    if (!list.length) {
      $target.append($("<li>").text("No notable items."));
      return;
    }

    list.forEach((item) => {
      $target.append($("<li>").text(String(item)));
    });
  }

  function renderAudit(audit) {
    const lists =
      rendererUtils && typeof rendererUtils.buildAuditLists === "function"
        ? rendererUtils.buildAuditLists(audit)
        : { preserved: [], transformed: [], stripped: [], followUp: [] };

    setAuditList($auditPreserved, lists.preserved);
    setAuditList($auditTransformed, lists.transformed);
    setAuditList($auditStripped, lists.stripped);
    setAuditList($auditFollowUp, lists.followUp);
  }

  function formatError(responseData) {
    if (rendererUtils && typeof rendererUtils.formatErrorMessage === "function") {
      return rendererUtils.formatErrorMessage(
        responseData || {},
        strings.requestFailed || "Request failed."
      );
    }

    return String((responseData && responseData.message) || strings.requestFailed || "Request failed.");
  }

  function showError(responseData) {
    $errorContent.text(formatError(responseData || {}));
    setPanelHidden($errorResult, false);
    setPanelHidden($previewResult, true);
    setPanelHidden($jsonResult, true);

    if (responseData?.audit) {
      renderAudit(responseData.audit);
    }
  }

  function renderPreview(responseData) {
    const data = responseData || {};
    const $content = $("<div>");

    function addPreviewStat(label, value) {
      const $stat = $('<div class="preview-stat">');
      $stat.append($("<span>").text(label));
      $stat.append($("<strong>").text(String(value)));
      $content.append($stat);
    }

    addPreviewStat("Total elements", data.elementCount || 0);
    addPreviewStat("Tailwind classes", data.tailwindClassCount || 0);
    addPreviewStat("Custom classes", data.customClassCount || 0);

    if (data.summary && data.summary.byType) {
      const $types = $('<div class="preview-types">');
      $types.append($("<strong>").text("Element types"));
      Object.entries(data.summary.byType).forEach(([type, count]) => {
        $types.append(
          $('<span class="preview-type">').text(type + " (" + count + ")")
        );
      });
      $content.append($types);
    }

    $previewContent.empty().append($content.children());
    setPanelHidden($previewResult, false);
    renderAudit(data.audit);
  }

  function renderConversion(responseData) {
    const data = responseData || {};
    if (state) {
      state.setLastConvertedJson(data.json || null);
    }

    if (data.json) {
      const prettyJson = JSON.stringify(JSON.parse(data.json), null, 2);
      $jsonOutput.val(prettyJson);
    }

    if (data.stats) {
      $("#report-elements").text(data.stats.elements || 0);
      $("#report-tailwind").text(data.stats.tailwindClasses || 0);
      $("#report-custom").text(data.stats.customClasses || 0);

      const $warnings = $("#report-warnings ul").empty();
      const warnings = Array.isArray(data.stats.warnings) ? data.stats.warnings : [];
      if (warnings.length) {
        warnings.forEach((warning) => $warnings.append($("<li>").text(String(warning))));
        $("#report-warnings").prop("hidden", false);
      } else {
        $("#report-warnings").prop("hidden", true);
      }

      const $info = $("#report-info ul").empty();
      const info = Array.isArray(data.stats.info) ? data.stats.info : [];
      if (info.length) {
        info.forEach((item) => $info.append($("<li>").text(String(item))));
        $("#report-info").prop("hidden", false);
      } else {
        $("#report-info").prop("hidden", true);
      }

      $reportSummary.prop("hidden", false);
    }

    $jsonStatus.text(
      rendererUtils && typeof rendererUtils.buildJsonStatus === "function"
        ? rendererUtils.buildJsonStatus(data)
        : "0 elements, 0 custom classes"
    );
    setPanelHidden($jsonResult, false);
    $copyBtn.prop("disabled", !(state && state.hasLastConvertedJson()));
    renderAudit(data.audit);
  }

  function runRequest(action, $button, onSuccess) {
    const html = String($htmlInput.val() || "").trim();
    if (!html) {
      showError({
        message:
          action === "oxy_html_convert_preview"
            ? strings.emptyPreview || "Paste HTML before previewing."
            : strings.emptyConvert || "Paste HTML before converting.",
      });
      return;
    }

    clearResults();
    showLoading($button);

    requestClient
      .request(action, html, getCurrentOptions())
      .then(function (response) {
        hideLoading($button);

        if (response.success) {
          onSuccess(response.data || {});
          return;
        }

        showError(response.data || { message: "Request failed." });
      })
      .catch(function (xhr) {
        hideLoading($button);
        showError(
          xhr.responseJSON?.data || {
            message:
              (strings.requestFailed || "Request failed:") +
              " " +
              (xhr.responseJSON?.data?.message || "Unknown error"),
          }
        );
      });
  }

  function previewHtml() {
    runRequest("oxy_html_convert_preview", $previewBtn, renderPreview);
  }

  function convertHtml() {
    runRequest("oxy_html_convert", $convertBtn, renderConversion);
  }

  function copyToClipboard() {
    if (!(state && state.hasLastConvertedJson())) {
      showError({ message: "Nothing to copy. Convert HTML first." });
      return;
    }

    navigator.clipboard
      .writeText(state.getLastConvertedJson())
      .then(function () {
        const originalText = $copyBtn.text();
        $copyBtn.text(strings.copied || "Copied");
        window.setTimeout(function () {
          $copyBtn.text(originalText);
        }, 1800);
      })
      .catch(function () {
        const $temp = $("<textarea>");
        $("body").append($temp);
        $temp.val(state.getLastConvertedJson()).trigger("select");
        document.execCommand("copy");
        $temp.remove();
      });
  }

  function applyPreset(preset) {
    isApplyingPreset = true;
    const values = presetUtils
        ? presetUtils.getPresetValues(preset)
        : {
            wrapInContainer: true,
            includeCssElement: false,
            inlineStyles: true,
            safeMode: true,
            allowExecutableCode: false,
            strictNative: false,
        };

    $wrapContainer.prop("checked", !!values.wrapInContainer);
    $includeCss.prop("checked", !!values.includeCssElement);
    $inlineStyles.prop("checked", !!values.inlineStyles);
    $safeMode.prop("checked", !!values.safeMode);
    $allowExecutableCode.prop("checked", !!values.allowExecutableCode);
    $strictNative.prop("checked", !!values.strictNative);
    isApplyingPreset = false;
  }

  function markOutputOutdated() {
    if (!(state && state.hasLastConvertedJson())) {
      return;
    }

    $copyBtn.prop("disabled", true);
    $jsonStatus.text(strings.outdated || "Output is outdated");
  }

  function handleManualOptionChange() {
    if (!isApplyingPreset) {
      const resolvedPreset = presetUtils
        ? presetUtils.resolvePresetFromOptions(getCurrentOptions())
        : "custom";
      $preset.val(resolvedPreset);
    }

    markOutputOutdated();
  }

  function loadSampleHtml() {
    const example = ui.examples?.hero || "";
    if (!example) {
      return;
    }

    $htmlInput.val(example).trigger("input").trigger("focus");
  }

  $previewBtn.on("click", previewHtml);
  $convertBtn.on("click", convertHtml);
  $copyBtn.on("click", copyToClipboard);
  $loadExampleBtn.on("click", loadSampleHtml);

  $htmlInput.on("input", markOutputOutdated);
  $preset.on("change", function () {
    const preset = String($preset.val() || "balanced");
    if (preset !== "custom") {
      applyPreset(preset);
    }
    markOutputOutdated();
  });

  $wrapContainer.on("change", handleManualOptionChange);
  $includeCss.on("change", handleManualOptionChange);
  $inlineStyles.on("change", handleManualOptionChange);
  $safeMode.on("change", handleManualOptionChange);
  $allowExecutableCode.on("change", handleManualOptionChange);
  $strictNative.on("change", handleManualOptionChange);

  applyPreset(String($preset.val() || "balanced"));

  $htmlInput.on("keydown", function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
      event.preventDefault();
      convertHtml();
    }
  });
})(jQuery);
