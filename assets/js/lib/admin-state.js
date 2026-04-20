(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterAdminState = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function createAdminState() {
    let lastConvertedJson = null;

    return {
      getLastConvertedJson: function getLastConvertedJson() {
        return lastConvertedJson;
      },
      setLastConvertedJson: function setLastConvertedJson(value) {
        lastConvertedJson = value || null;
      },
      clearLastConvertedJson: function clearLastConvertedJson() {
        lastConvertedJson = null;
      },
      hasLastConvertedJson: function hasLastConvertedJson() {
        return !!lastConvertedJson;
      },
    };
  }

  return {
    createAdminState: createAdminState,
  };
});
