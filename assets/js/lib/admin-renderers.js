(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterAdminRenderers = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function buildAuditLists(audit) {
    if (!audit || typeof audit !== "object") {
      return {
        preserved: [],
        transformed: [],
        stripped: [],
        followUp: [],
      };
    }

    const preserved = [];
    const transformed = [];
    const preservedData = audit.preserved || {};
    const summary = audit.summary || {};
    const transformedData = audit.transformed || {};

    if (Array.isArray(preservedData.customClasses) && preservedData.customClasses.length) {
      preserved.push(preservedData.customClasses.length + " custom class token(s) preserved.");
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
          (preservedData.headAssets && preservedData.headAssets.links ? preservedData.headAssets.links : 0) + " link(s)",
          (preservedData.headAssets && preservedData.headAssets.scripts ? preservedData.headAssets.scripts : 0) + " script(s)",
          (preservedData.headAssets && preservedData.headAssets.iconScripts ? preservedData.headAssets.iconScripts : 0) + " icon script(s)",
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
      transformed.push.apply(transformed, transformedData.info);
    }

    return {
      preserved: preserved,
      transformed: transformed,
      stripped: Array.isArray(audit.stripped) ? audit.stripped : [],
      followUp: Array.isArray(audit.followUp) ? audit.followUp : [],
    };
  }

  function formatErrorMessage(responseData, fallbackMessage) {
    const parts = [];
    const message =
      (responseData && responseData.message) ||
      fallbackMessage ||
      "Request failed.";

    parts.push(String(message));

    const errors = Array.isArray(responseData && responseData.errors)
      ? responseData.errors
      : [];
    if (errors.length) {
      parts.push("Details: " + errors.join(" | "));
    }

    const followUp =
      responseData && responseData.audit && Array.isArray(responseData.audit.followUp)
        ? responseData.audit.followUp
        : [];
    if (followUp.length) {
      parts.push("Next steps: " + followUp.join(" | "));
    }

    return parts.join("\n\n");
  }

  function buildJsonStatus(data) {
    const classCount = Array.isArray(data && data.customClasses) ? data.customClasses.length : 0;
    const elementCount =
      data && data.audit && data.audit.summary && data.audit.summary.elements
        ? data.audit.summary.elements
        : 0;

    return elementCount + " elements, " + classCount + " custom classes";
  }

  return {
    buildAuditLists: buildAuditLists,
    formatErrorMessage: formatErrorMessage,
    buildJsonStatus: buildJsonStatus,
  };
});
