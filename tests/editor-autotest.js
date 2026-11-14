/* Scripted checks of the wp-admin directory editor, run by tests/ui.php (/editor?autotest=1, and
   /editor?autotest=1&loadfail=1 for the case where the librarian list fails to load). */
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
  function type(el, v) { el.value = v; el.dispatchEvent(new Event("input", { bubbles: true })); if (el.oninput) el.oninput(); }
  var posts = [], realFetch = window.fetch;
  window.fetch = function (u, o) { if (o && o.method === "POST") posts.push(JSON.parse(o.body)); return realFetch.apply(this, arguments); };
  var status = function () { return $("#meetlib-status").textContent; };

  try {
    if (/loadfail/.test(location.search)) {
      await until(function () { return /could not be loaded/.test(status()); }, "load failure message");
      ok($("#meetlib-save").disabled, "Save is disabled when the list did not load");
      ok($("#meetlib-add").disabled, "Add is disabled when the list did not load");
      $("#meetlib-save").disabled = false; // even if someone re-enables it
      $("#meetlib-save").click();
      await sleep(200);
      ok(posts.length === 0, "nothing is saved when the list did not load");
    } else {
      await until(function () { return $$("#meetlib-rows .card").length === 3; }, "three librarian cards");
      ok(/calendar will appear/.test($("#meetlib-0-booking-note").textContent), "Google appointment link is recognised");
      ok(/not a full Google appointment link/.test($("#meetlib-1-booking-note").textContent), "short link gets a warning");
      ok($("#meetlib-2-booking-note").textContent === "", "empty link has no note");

      // Typing keeps focus and updates the note.
      var b = $("#meetlib-2-booking"); b.focus();
      type(b, "https://calendar.google.com/calendar/u/0/appointments/schedules/ZZZ");
      ok(document.activeElement === b, "typing does not steal focus");
      ok(/calendar will appear/.test($("#meetlib-2-booking-note").textContent), "note updates while typing");

      // Save.
      $("#meetlib-save").click();
      await until(function () { return /Saved/.test(status()); }, "saved message");
      ok(posts.length === 1 && posts[0].librarians.length === 3, "all three librarians sent");
      ok(posts[0].librarians[0].embed === undefined, "computed fields are not sent back");
      ok(posts[0].librarians[2].booking.indexOf("ZZZ") > 0, "edited link sent");

      // Removing everyone must not save an empty directory.
      $$("#meetlib-rows .tiny").forEach(function () { $("#meetlib-rows .tiny").click(); });
      ok($$("#meetlib-rows .card").length === 0, "all cards removed on screen");
      $("#meetlib-save").click();
      await sleep(200);
      ok(posts.length === 1 && /Nothing was saved/.test(status()), "an empty directory is not saved");
    }
  } catch (e) {
    ok(false, "exception: " + (e && e.stack ? e.stack : e));
  }
  var pre = document.createElement("pre");
  pre.id = "results";
  pre.textContent = JSON.stringify({ fails: fails, total: out.length, lines: out });
  document.body.appendChild(pre);
})();
