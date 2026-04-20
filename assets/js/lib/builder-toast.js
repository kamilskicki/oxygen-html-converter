(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterBuilderToast = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function showToast(parentWindow, message, duration) {
    const parentDoc = parentWindow.document;
    const timeout = typeof duration === "number" ? duration : 3200;
    let toast = parentDoc.getElementById("oxy-html-converter-toast");

    if (!toast) {
      toast = parentDoc.createElement("div");
      toast.id = "oxy-html-converter-toast";
      toast.style.cssText = [
        "position:fixed",
        "top:24px",
        "right:24px",
        "z-index:999999",
        "max-width:360px",
        "padding:14px 16px",
        "background:#112033",
        "color:#fff",
        "border-radius:12px",
        "font-size:13px",
        "line-height:1.5",
        "box-shadow:0 18px 40px rgba(17,32,51,0.25)",
        "opacity:0",
        "transform:translateY(-8px)",
        "transition:opacity .2s ease, transform .2s ease",
        "pointer-events:none",
      ].join(";");
      parentDoc.body.appendChild(toast);
    }

    toast.textContent = message;
    toast.style.opacity = "1";
    toast.style.transform = "translateY(0)";

    parentWindow.setTimeout(function () {
      toast.style.opacity = "0";
      toast.style.transform = "translateY(-8px)";
    }, timeout);
  }

  return {
    showToast: showToast,
  };
});
