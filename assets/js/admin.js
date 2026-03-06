(function ($) {
  "use strict";

  const config = window.oxyHtmlConverterAdmin || {};
  let lastConvertedJson = null;

  // DOM elements
  const $htmlInput = $("#oxy-html-input");
  const $previewBtn = $("#oxy-preview-btn");
  const $convertBtn = $("#oxy-convert-btn");
  const $copyBtn = $("#oxy-copy-btn");
  const $wrapContainer = $("#oxy-wrap-container");
  const $includeCss = $("#oxy-include-css");
  const $inlineStyles = $("#oxy-inline-styles");
  const $safeMode = $("#oxy-safe-mode");
  const $preset = $("#oxy-convert-preset");
  const presetUtils = window.OxyHtmlConverterPresets || null;
  let isApplyingPreset = false;

  const $previewResult = $("#oxy-preview-result");
  const $previewContent = $("#oxy-preview-content");
  const $jsonResult = $("#oxy-json-result");
  const $jsonOutput = $("#oxy-json-output");
  const $jsonStatus = $(".oxy-json-status");
  const $errorResult = $("#oxy-error-result");
  const $errorContent = $("#oxy-error-content");

  /**
   * Show loading state
   */
  function showLoading($btn) {
    $btn.addClass("oxy-loading").prop("disabled", true);
  }

  /**
   * Hide loading state
   */
  function hideLoading($btn) {
    $btn.removeClass("oxy-loading").prop("disabled", false);
  }

  /**
   * Show error message
   */
  function showError(message) {
    $errorContent.text(message);
    $errorResult.show();
    $previewResult.hide();
    $jsonResult.hide();
  }

  /**
   * Clear all results
   */
  function clearResults() {
    $previewResult.hide();
    $jsonResult.hide();
    $errorResult.hide();
    $copyBtn.prop("disabled", true);
    lastConvertedJson = null;
  }

  /**
   * Apply a conversion preset to option checkboxes.
   */
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

  function getCurrentOptions() {
    return {
      wrapInContainer: $wrapContainer.is(":checked"),
      includeCssElement: $includeCss.is(":checked"),
      inlineStyles: $inlineStyles.is(":checked"),
      safeMode: $safeMode.is(":checked"),
    };
  }

  /**
   * Preview HTML conversion
   */
  function previewHtml() {
    const html = $htmlInput.val().trim();
    if (!html) {
      showError("Please enter some HTML to preview.");
      return;
    }

    clearResults();
    showLoading($previewBtn);

    $.ajax({
      url: config.ajaxUrl,
      type: "POST",
      data: {
        action: "oxy_html_convert_preview",
        nonce: config.nonce,
        html: html,
        inlineStyles: $inlineStyles.is(":checked"),
        safeMode: $safeMode.is(":checked"),
      },
      success: function (response) {
        hideLoading($previewBtn);

        if (response.success) {
          const data = response.data;
          const $content = $("<div>");

          function addPreviewStat(label, value) {
            const $stat = $('<div class="preview-stat">');
            $stat.append($("<span>").text(label));
            $stat.append($("<strong>").text(String(value)));
            $content.append($stat);
          }

          addPreviewStat("Total Elements:", data.elementCount);
          addPreviewStat("Tailwind Classes:", data.tailwindClassCount || 0);
          addPreviewStat("Custom Classes:", data.customClassCount || 0);

          if (data.summary && data.summary.byType) {
            const $types = $('<div class="preview-types">');
            $types.append($("<strong>").text("Element Types:"));
            $types.append("<br>");
            for (const [type, count] of Object.entries(data.summary.byType)) {
              $types.append(
                $('<span class="preview-type">').text(type + " (" + count + ")")
              );
            }
            $content.append($types);
          }

          $previewContent.empty().append($content.children());
          $previewResult.show();
        } else {
          showError(response.data?.message || "Preview failed.");
        }
      },
      error: function (xhr) {
        hideLoading($previewBtn);
        showError(
          "Request failed: " +
            (xhr.responseJSON?.data?.message || "Unknown error")
        );
      },
    });
  }

  /**
   * Convert HTML to Oxygen JSON
   */
  function convertHtml() {
    const html = $htmlInput.val().trim();
    if (!html) {
      showError("Please enter some HTML to convert.");
      return;
    }

    clearResults();
    showLoading($convertBtn);

    $.ajax({
      url: config.ajaxUrl,
      type: "POST",
      data: {
        action: "oxy_html_convert",
        nonce: config.nonce,
        html: html,
        wrapInContainer: $wrapContainer.is(":checked"),
        includeCssElement: $includeCss.is(":checked"),
        inlineStyles: $inlineStyles.is(":checked"),
        safeMode: $safeMode.is(":checked"),
      },
      success: function (response) {
        hideLoading($convertBtn);

        if (response.success) {
          const data = response.data;
          lastConvertedJson = data.json;

          // Pretty print JSON
          const prettyJson = JSON.stringify(JSON.parse(data.json), null, 2);
          $jsonOutput.val(prettyJson);

          // Show detailed report
          if (data.stats) {
            $("#report-elements").text(data.stats.elements);
            $("#report-tailwind").text(data.stats.tailwindClasses);
            $("#report-custom").text(data.stats.customClasses);

            const $warnings = $("#report-warnings ul").empty();
            if (data.stats.warnings && data.stats.warnings.length > 0) {
              data.stats.warnings.forEach((w) =>
                $warnings.append($("<li>").text(String(w)))
              );
              $("#report-warnings").show();
            } else {
              $("#report-warnings").hide();
            }

            const $info = $("#report-info ul").empty();
            if (data.stats.info && data.stats.info.length > 0) {
              data.stats.info.forEach((i) =>
                $info.append($("<li>").text(String(i)))
              );
              $("#report-info").show();
            } else {
              $("#report-info").hide();
            }

            $("#oxy-report-summary").show();
          }

          // Show stats
          const elementCount = countElements(data.element);
          const classCount = data.customClasses ? data.customClasses.length : 0;
          $jsonStatus.text(
            elementCount + " elements, " + classCount + " custom classes"
          );

          $jsonResult.show();
          $copyBtn.prop("disabled", false);
        } else {
          showError(response.data?.message || "Conversion failed.");
        }
      },
      error: function (xhr) {
        hideLoading($convertBtn);
        showError(
          "Request failed: " +
            (xhr.responseJSON?.data?.message || "Unknown error")
        );
      },
    });
  }

  /**
   * Count elements in tree
   */
  function countElements(element) {
    if (!element) return 0;
    let count = 1;
    if (element.children) {
      for (const child of element.children) {
        count += countElements(child);
      }
    }
    return count;
  }

  /**
   * Copy JSON to clipboard
   */
  function copyToClipboard() {
    if (!lastConvertedJson) {
      showError("Nothing to copy. Convert HTML first.");
      return;
    }

    // Use the compact JSON for clipboard (not pretty printed)
    navigator.clipboard
      .writeText(lastConvertedJson)
      .then(function () {
        const originalText = $copyBtn.text();
        $copyBtn.text("Copied!");
        setTimeout(function () {
          $copyBtn.text(originalText);
        }, 2000);
      })
      .catch(function (err) {
        // Fallback for older browsers
        const $temp = $("<textarea>");
        $("body").append($temp);
        $temp.val(lastConvertedJson).select();
        document.execCommand("copy");
        $temp.remove();

        const originalText = $copyBtn.text();
        $copyBtn.text("Copied!");
        setTimeout(function () {
          $copyBtn.text(originalText);
        }, 2000);
      });
  }

  // Event handlers
  $previewBtn.on("click", previewHtml);
  $convertBtn.on("click", convertHtml);
  $copyBtn.on("click", copyToClipboard);

  // Clear results when input changes
  function markOutputOutdated() {
    if (lastConvertedJson) {
      $copyBtn.prop("disabled", true);
      $jsonStatus.text("(outdated)");
    }
  }

  $htmlInput.on("input", markOutputOutdated);
  $preset.on("change", function () {
    const preset = $preset.val();
    if (preset !== "custom") {
      applyPreset(String(preset));
    }
    markOutputOutdated();
  });

  function handleManualOptionChange() {
    if (!isApplyingPreset) {
      const resolvedPreset = presetUtils
        ? presetUtils.resolvePresetFromOptions(getCurrentOptions())
        : "custom";
      $preset.val(resolvedPreset);
    }
    markOutputOutdated();
  }

  $wrapContainer.on("change", handleManualOptionChange);
  $includeCss.on("change", handleManualOptionChange);
  $inlineStyles.on("change", handleManualOptionChange);
  $safeMode.on("change", handleManualOptionChange);

  // Ensure options match the default selected preset on load.
  applyPreset(String($preset.val() || "balanced"));

  // Keyboard shortcuts
  $htmlInput.on("keydown", function (e) {
    // Ctrl/Cmd + Enter to convert
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      convertHtml();
    }
  });
})(jQuery);
