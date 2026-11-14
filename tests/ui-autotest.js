/* Scripted walk through the patron form, run by tests/ui.php?autotest=1 in a headless browser.
   Writes its results as JSON into <pre id="results">. */
(async function () {
  var out = [], fails = 0;
  function ok(cond, what) { out.push((cond ? "ok   " : "FAIL ") + what); if (!cond) fails++; }
  function $(s) { return document.querySelector(s); }
  function $$(s) { return Array.prototype.slice.call(document.querySelectorAll(s)); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  async function until(fn, what) {
    for (var i = 0; i < 100; i++) { if (fn()) return true; await sleep(30); }
    ok(false, "timed out waiting for: " + what); return false;
  }
  function fill(email) {
    $("#meetlib-f-email").value = email;
    $("#meetlib-f-help").value = "Finding sources";
    $("#meetlib-f-course").value = "ENG 101";
    $$('input[name="meetlib_meeting"]')[2].checked = true;
  }
  function send() { $("#meetlib-f").dispatchEvent(new Event("submit", { cancelable: true, bubbles: true })); }
  var realFetch = window.fetch;

  try {
    sessionStorage.clear();
    await until(function () { return $$(".meetlib__card").length === 3; }, "three librarian cards");
    var books = $$(".meetlib__book");
    ok(books.length === 2, "two librarians can be booked");
    ok($$(".meetlib__soon").length === 1, "librarian without a link shows 'coming soon'");

    // Open the form.
    books[0].click();
    ok(!!$("#meetlib-f"), "form opens");
    ok(!!$("#meetlib-lock"), "calendar starts locked");
    ok($("#meetlib-cal-ctl").tagName === "IFRAME" && $("#meetlib-cal-ctl").getAttribute("aria-hidden") === "true", "locked calendar is hidden from assistive tech");
    ok($("#meetlib-alt").hidden === true, "fallback link hidden while locked");
    ok(!!$('input[name="meetlib_hp"]') && !$('input[name="website"]'), "honeypot uses the new name");
    ok($("#meetlib-f-help").getAttribute("maxlength") === "5000" && $("#meetlib-f-course").getAttribute("maxlength") === "300", "length limits set");
    ok(/Max 10 MB/.test($("#meetlib-f-attachment-help").textContent), "attachment help shows the real limit");
    ok(document.activeElement === $("#meetlib-h1"), "focus moves to the heading");

    // Empty submit.
    var calls = 0;
    window.fetch = function () { calls++; return realFetch.apply(this, arguments); };
    send();
    ok(calls === 0, "empty form is not sent");
    ok($('[data-err="email"]').textContent !== "" && $("#meetlib-f-email").getAttribute("aria-invalid") === "true", "required errors shown");
    ok(document.activeElement === $("#meetlib-f-email"), "focus moves to the first problem");

    // Outside address.
    fill("someone@gmail.com"); send();
    ok(calls === 0 && /wheatoncollege\.edu/.test($('[data-err="email"]').textContent), "outside email refused in the browser");

    // Server refuses.
    fill("student@wheatoncollege.edu");
    window.fetch = function () { return Promise.resolve(new Response(JSON.stringify({ message: "Too many requests in a short time." }), { status: 429 })); };
    send();
    await until(function () { return $("#meetlib-alert").textContent !== ""; }, "server error shown");
    ok(/Too many requests/.test($("#meetlib-alert").textContent), "server's message is shown");
    ok(!!$('#meetlib-alert a[href="mailto:cary@wheatoncollege.edu"]'), "error offers the librarian's email");
    ok(!$('#meetlib-f button[type="submit"]').disabled && !!$("#meetlib-lock"), "form usable again, calendar still locked");

    // Network down.
    window.fetch = function () { return Promise.reject(new TypeError("Failed to fetch")); };
    send();
    await until(function () { return /internet connection/.test($("#meetlib-alert").textContent); }, "network error shown");
    ok(!/Failed to fetch/.test($("#meetlib-alert").textContent), "no raw browser error text");

    // Real submit against the local server.
    var sentBody = null;
    window.fetch = function (u, o) { sentBody = o && o.body; return realFetch.apply(this, arguments); };
    send();
    await until(function () { return !$("#meetlib-lock"); }, "calendar unlocks after sending");
    ok(sentBody && sentBody.get("email") === "student@wheatoncollege.edu" && sentBody.get("librarianSlug") === "cary-gouldin", "form data sent");
    ok(sentBody && sentBody.get("_wpnonce") === null && sentBody.get("meetlib_hp") === "", "no nonce, empty honeypot");
    ok(!$("#meetlib-cal-ctl").hasAttribute("aria-hidden") && !$("#meetlib-cal-ctl").hasAttribute("tabindex"), "calendar reachable after sending");
    ok($("#meetlib-alt").hidden === false, "fallback link shown after sending");
    ok(/student@wheatoncollege\.edu/.test($("#meetlib-form-body").textContent), "patron is told which email to book with");
    ok(document.activeElement === $("#meetlib-h3"), "focus moves to the calendar heading");
    ok(!!sessionStorage.getItem("meetlib_sent_cary-gouldin"), "sent state remembered");

    // Reopen (as after a reload): still unlocked, no second form.
    books[0].click();
    ok(!$("#meetlib-lock") && !$("#meetlib-f"), "reopening shows the unlocked calendar, not the form again");

    // A second, different request.
    $("#meetlib-again").click();
    ok(!!$("#meetlib-lock") && !!$("#meetlib-f"), "'Send a different request' brings the form and lock back");
    ok(!sessionStorage.getItem("meetlib_sent_cary-gouldin"), "remembered state cleared");

    // A 200 reply that is not JSON still counts as sent.
    fill("student@wheatoncollege.edu");
    window.fetch = function () { return Promise.resolve(new Response("<br>PHP Notice: something</br>{\"ok\":true}", { status: 200 })); };
    send();
    await until(function () { return !$("#meetlib-lock"); }, "garbled success reply still unlocks");

    // Librarian whose link cannot be embedded.
    window.fetch = realFetch;
    books[1].click();
    ok($("#meetlib-cal-ctl").tagName === "A" && $("#meetlib-cal-ctl").getAttribute("tabindex") === "-1", "non-embeddable link is a locked button");
    ok(!$("#meetlib-alt"), "no fallback link needed for it");

    // Cancel.
    $("#meetlib-cancel").click();
    ok($("#meetlib-form-host").innerHTML === "", "cancel closes the form");
  } catch (e) {
    ok(false, "exception: " + (e && e.stack ? e.stack : e));
  }
  var pre = document.createElement("pre");
  pre.id = "results";
  pre.textContent = JSON.stringify({ fails: fails, total: out.length, lines: out });
  document.body.appendChild(pre);
})();
