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
      },
      success: function (response) {
        hideLoading($previewBtn);

        if (response.success) {
          const data = response.data;
          let html = "";

          html +=
            '<div class="preview-stat"><span>Total Elements:</span><strong>' +
            data.elementCount +
            "</strong></div>";
          html +=
            '<div class="preview-stat"><span>Tailwind Classes:</span><strong>' +
            (data.tailwindClassCount || 0) +
            "</strong></div>";
          html +=
            '<div class="preview-stat"><span>Custom Classes:</span><strong>' +
            (data.customClassCount || 0) +
            "</strong></div>";

          if (data.summary && data.summary.byType) {
            html +=
              '<div class="preview-types"><strong>Element Types:</strong><br>';
            for (const [type, count] of Object.entries(data.summary.byType)) {
              html +=
                '<span class="preview-type">' +
                type +
                " (" +
                count +
                ")</span>";
            }
            html += "</div>";
          }

          $previewContent.html(html);
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
                $warnings.append("<li>" + w + "</li>")
              );
              $("#report-warnings").show();
            } else {
              $("#report-warnings").hide();
            }

            const $info = $("#report-info ul").empty();
            if (data.stats.info && data.stats.info.length > 0) {
              data.stats.info.forEach((i) =>
                $info.append("<li>" + i + "</li>")
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
  $htmlInput.on("input", function () {
    if (lastConvertedJson) {
      $copyBtn.prop("disabled", true);
      $jsonStatus.text("(outdated)");
    }
  });

  // Keyboard shortcuts
  $htmlInput.on("keydown", function (e) {
    // Ctrl/Cmd + Enter to convert
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      convertHtml();
    }
  });
})(jQuery);
