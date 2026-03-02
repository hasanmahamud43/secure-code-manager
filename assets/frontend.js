(function () {
  function qs(id) { return document.getElementById(id); }

  function setResult(el, msg, ok) {
    el.textContent = msg || "";
    el.classList.remove("scm-ok", "scm-err");
    if (msg) el.classList.add(ok ? "scm-ok" : "scm-err");
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (typeof SCM_VERIFY === "undefined") return;

    var input = qs("scm_code_input");
    var btn = qs("scm_verify_btn");
    var result = qs("scm_result");

    if (!input || !btn || !result) return;

    btn.addEventListener("click", function () {
      var code = (input.value || "").trim();

      setResult(result, "", false);

      if (!/^\d{6,}$/.test(code)) {
        setResult(result, "Invalid Code", false);
        return;
      }

      var num = parseInt(code, 10);
      if (isNaN(num) || num < SCM_VERIFY.min || num > SCM_VERIFY.max) {
        setResult(result, "Invalid Code", false);
        return;
      }

      btn.disabled = true;
      btn.textContent = "Verifying...";

      var form = new FormData();
      form.append("action", "scm_verify_code");
      form.append("nonce", SCM_VERIFY.nonce);
      form.append("code", code);

      fetch(SCM_VERIFY.ajax_url, {
        method: "POST",
        body: form,
        credentials: "same-origin"
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success) {
            setResult(result, data.data && data.data.message ? data.data.message : "Verified Original", true);
          } else {
            setResult(result, (data && data.data && data.data.message) ? data.data.message : "Invalid Code", false);
          }
        })
        .catch(function () {
          setResult(result, "Something went wrong. Please try again.", false);
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = "Verify";
        });
    });
  });
})();