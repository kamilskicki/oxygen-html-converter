(function ($) {
  "use strict";

  const config = window.oxyHtmlConverterAdmin || {};
  const strings = config.strings || {};
  const ui = config.ui || {};
  const presetUtils = window.OxyHtmlConverterPresets || null;
  const optionUtils = window.OxyHtmlConverterOptions || null;

  let lastConvertedJson = null;
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
      debugMode: false,
    };
  }

  function buildRequestFields() {
    return optionUtils
      ? optionUtils.buildConvertRequestFields(getCurrentOptions())
      : {
          wrapInContainer: $wrapContainer.is(":checked"),
          includeCssElement: $includeCss.is(":checked"),
          inlineStyles: $inlineStyles.is(":checked"),
          safeMode: $safeMode.is(":checked"),
          debugMode: false,
        };
  }

  function clearResults() {
    setPanelHidden($previewResult, true);
    setPanelHidden($jsonResult, true);
    setPanelHidden($errorResult, true);
    $copyBtn.prop("disabled", true);
    lastConvertedJson = null;
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
    if (!audit || typeof audit !== "object") {
      setAuditList($auditPreserved, []);
      setAuditList($auditTransformed, []);
      setAuditList($auditStripped, []);
      setAuditList($auditFollowUp, []);
      return;
    }

    const preserved = [];
    const transformed = [];

    const preservedData = audit.preserved || {};
    const summary = audit.summary || {};
    const transformedData = audit.transformed || {};

    if (Array.isArray(preservedData.customClasses) && preservedData.customClasses.length) {
      preserved.push(
        preservedData.customClasses.length + " custom class token(s) preserved."
      );
    }

    if (Array.isArray(preservedData.iconLibraries) && preservedData.iconLibraries.length) {
      preserved.push("Icon libraries detected: " + preservedData.iconLibraries.join(", "));
    }

    if (summary.hasExtractedCss) {
      preserved.push("Extracted CSS will be available to the import flow.");
    }

    preserved.push(
      "Head assets preserved: " +
        [
          (preservedData.headAssets?.links || 0) + " link(s)",
          (preservedData.headAssets?.scripts || 0) + " script(s)",
          (preservedData.headAssets?.iconScripts || 0) + " icon script(s)",
        ].join(", ")
    );

    transformed.push("Elements converted: " + String(summary.elements || 0));
    transformed.push(
      (transformedData.wrapInContainer ? "Wrapped" : "Did not wrap") +
        " root output in a container."
    );
    transformed.push(
      (transformedData.includeCssElement ? "Included" : "Skipped") +
        " extracted CSS element."
    );
    transformed.push(
      (transformedData.inlineStyles ? "Mapped" : "Did not map") +
        " supported inline/class styles to Oxygen properties."
    );

    if (Array.isArray(transformedData.info) && transformedData.info.length) {
      transformed.push(...transformedData.info);
    }

    setAuditList($auditPreserved, preserved);
    setAuditList($auditTransformed, transformed);
    setAuditList($auditStripped, audit.stripped || []);
    setAuditList($auditFollowUp, audit.followUp || []);
  }

  function formatError(responseData) {
    const parts = [];
    const message = responseData?.message || strings.requestFailed || "Request failed.";

    parts.push(String(message));

    const errors = Array.isArray(responseData?.errors) ? responseData.errors : [];
    if (errors.length) {
      parts.push("Details: " + errors.join(" | "));
    }

    const followUp = responseData?.audit?.followUp || [];
    if (Array.isArray(followUp) && followUp.length) {
      parts.push("Next steps: " + followUp.join(" | "));
    }

    return parts.join("\n\n");
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
    lastConvertedJson = data.json || null;

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

    const classCount = Array.isArray(data.customClasses) ? data.customClasses.length : 0;
    $jsonStatus.text(
      (data.audit?.summary?.elements || 0) + " elements, " + classCount + " custom classes"
    );
    setPanelHidden($jsonResult, false);
    $copyBtn.prop("disabled", !lastConvertedJson);
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

    $.ajax({
      url: config.ajaxUrl,
      type: "POST",
      data: Object.assign(
        {
          action: action,
          nonce: config.nonce,
          html: html,
        },
        buildRequestFields()
      ),
      success: function (response) {
        hideLoading($button);

        if (response.success) {
          onSuccess(response.data || {});
          return;
        }

        showError(response.data || { message: "Request failed." });
      },
      error: function (xhr) {
        hideLoading($button);
        showError(
          xhr.responseJSON?.data || {
            message:
              (strings.requestFailed || "Request failed:") +
              " " +
              (xhr.responseJSON?.data?.message || "Unknown error"),
          }
        );
      },
    });
  }

  function previewHtml() {
    runRequest("oxy_html_convert_preview", $previewBtn, renderPreview);
  }

  function convertHtml() {
    runRequest("oxy_html_convert", $convertBtn, renderConversion);
  }

  function copyToClipboard() {
    if (!lastConvertedJson) {
      showError({ message: "Nothing to copy. Convert HTML first." });
      return;
    }

    navigator.clipboard
      .writeText(lastConvertedJson)
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
        $temp.val(lastConvertedJson).trigger("select");
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
          includeCssElement: true,
          inlineStyles: true,
          safeMode: false,
        };

    $wrapContainer.prop("checked", !!values.wrapInContainer);
    $includeCss.prop("checked", !!values.includeCssElement);
    $inlineStyles.prop("checked", !!values.inlineStyles);
    $safeMode.prop("checked", !!values.safeMode);
    isApplyingPreset = false;
  }

  function markOutputOutdated() {
    if (!lastConvertedJson) {
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

  applyPreset(String($preset.val() || "balanced"));

  $htmlInput.on("keydown", function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key === "Enter") {
      event.preventDefault();
      convertHtml();
    }
  });
})(jQuery);
