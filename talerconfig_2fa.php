<?php
/* Copyright (C) 2026       Bohdan Potuzhnyi
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *    \file       talerbarr/talerconfig_2fa.php
 *    \ingroup    talerbarr
 *    \brief      Two-factor verification page for merchant token creation
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

$langs->loadLangs(array('talerbarr@talerbarr', 'other'));

$ctxId = GETPOST('ctx', 'aZ09');
$cardUrl = dol_buildpath('/talerbarr/talerconfig_card.php', 1);

$enablepermissioncheck = getDolGlobalInt('TALERBARR_ENABLE_PERMISSION_CHECK');
$permissiontoadd = $enablepermissioncheck ? $user->hasRight('talerbarr', 'talerconfig', 'write') : 1;
if (!isModEnabled('talerbarr')) {
	accessforbidden('Module talerbarr not enabled');
}
if (!$permissiontoadd) {
	accessforbidden();
}

$title = $langs->trans('TalerTwoFactorTitle');
if ($title === 'TalerTwoFactorTitle') {
	$title = 'Taler token verification';
}

$morecss = array('/custom/talerbarr/css/talerbarr.css');
llxHeader('', $title, '', '', 0, 0, array(), $morecss, '', 'mod-talerbarr page-2fa');

print load_fiche_titre($title, '', 'lock');
print '<div class="taler-home-wrap fichecenter">';
print '  <div class="taler-home-card">';
print '    <div id="taler2fa-app">Loading verification flow...</div>';
print '  </div>';
print '</div>';

print '<script>
(function(){
  var ctxId = '.json_encode($ctxId).';
  var fallbackCardUrl = '.json_encode($cardUrl).';
  var storageKey = "talerbarr.tokenctx." + ctxId;

  var app = document.getElementById("taler2fa-app");
  var state = {
    ctx: null,
    selectedChallengeId: "",
    confirmed: {},
    requested: {},
    busy: false
  };

  function esc(v){
    return String(v == null ? "" : v)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/\'/g, "&#39;");
  }

  function byId(id){ return document.getElementById(id); }

  function setNotice(msg, isError){
    var el = byId("tb-notice");
    if (!el) return;
    if (!msg) {
      el.className = "tb2fa-note";
      el.textContent = "";
      return;
    }
    el.className = isError ? "tb2fa-note error" : "tb2fa-note ok";
    el.textContent = msg || "";
  }

  function goBackToCard(){
    var url = fallbackCardUrl;
    if (state.ctx && typeof state.ctx.formAction === "string" && state.ctx.formAction.length) {
      url = state.ctx.formAction;
    }
    clearContext();
    window.location.href = url;
  }

  function loadContext(){
    if (!ctxId) return null;
    var raw = "";
    try {
      raw = window.sessionStorage.getItem(storageKey) || "";
    } catch (e) {
      return null;
    }
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e2) {
      return null;
    }
  }

  function saveContext(){
    if (!state.ctx) return;
    try {
      window.sessionStorage.setItem(storageKey, JSON.stringify(state.ctx));
    } catch (e) {}
  }

  function clearContext(){
    try {
      window.sessionStorage.removeItem(storageKey);
    } catch (e) {}
  }

  function getChallenges(){
    var cr = state.ctx && state.ctx.challengeResponse ? state.ctx.challengeResponse : {};
    if (!cr || !Array.isArray(cr.challenges)) return [];
    return cr.challenges.filter(function(c){
      return c && typeof c.challenge_id === "string" && c.challenge_id.length;
    });
  }

  function hasAndCombination(){
    var cr = state.ctx && state.ctx.challengeResponse ? state.ctx.challengeResponse : {};
    return !!cr.combi_and;
  }

  function requiredCount(){
    var challenges = getChallenges();
    if (!challenges.length) return 0;
    return hasAndCombination() ? challenges.length : 1;
  }

  function confirmedIds(){
    return Object.keys(state.confirmed).filter(function(id){ return state.confirmed[id]; });
  }

  function challengeLabel(c){
    var chan = c && c.tan_channel ? String(c.tan_channel).toUpperCase() : "METHOD";
    var info = c && c.tan_info ? String(c.tan_info) : "";
    return info ? (chan + " - " + info) : chan;
  }

  function challengeById(id){
    var list = getChallenges();
    for (var i = 0; i < list.length; i++) {
      if (list[i].challenge_id === id) return list[i];
    }
    return null;
  }

  function progressPercent(){
    var needed = requiredCount();
    if (needed <= 0) return 100;
    var done = confirmedIds().length;
    var base = 50; // Step 1 (credentials entered) is already completed on this page.
    var pct = base + Math.round((done / needed) * 50);
    return Math.max(base, Math.min(100, pct));
  }

  function isRequested(id){
    return !!state.requested[id];
  }

  function ensureSelected(){
    var challenges = getChallenges();
    if (!challenges.length) {
      state.selectedChallengeId = "";
      return;
    }
    var ok = challenges.some(function(c){ return c.challenge_id === state.selectedChallengeId; });
    var pending = challenges.filter(function(c){ return !state.confirmed[c.challenge_id]; });
    if (!ok || (state.confirmed[state.selectedChallengeId] && pending.length)) {
      state.selectedChallengeId = (pending.length ? pending[0].challenge_id : challenges[0].challenge_id);
    }
  }

  function render(){
    if (!state.ctx) {
      app.innerHTML = ""
        + "<div class=\"tb2fa-banner tb2fa-banner-error\">Missing verification context. Please retry from the configuration form.</div>"
        + "<div class=\"center\" style=\"margin-top:12px\"><button type=\"button\" class=\"button\" id=\"tb-back\">Back</button></div>";
      var backBtn = byId("tb-back");
      if (backBtn) backBtn.onclick = goBackToCard;
      return;
    }

    var challenges = getChallenges();
    if (!challenges.length) {
      app.innerHTML = ""
        + "<div class=\"tb2fa-banner tb2fa-banner-error\">No verification methods were provided by the merchant backend.</div>"
        + "<div class=\"center\" style=\"margin-top:12px\"><button type=\"button\" class=\"button\" id=\"tb-back\">Back</button></div>";
      var backBtn2 = byId("tb-back");
      if (backBtn2) backBtn2.onclick = goBackToCard;
      return;
    }

    ensureSelected();

    var options = "";
    for (var i = 0; i < challenges.length; i++) {
      var c = challenges[i];
      var selected = (c.challenge_id === state.selectedChallengeId) ? " selected" : "";
      options += "<option value=\"" + esc(c.challenge_id) + "\"" + selected + ">" + esc(challengeLabel(c)) + "</option>";
    }

    var done = confirmedIds();
    var needed = requiredCount();
    var enough = done.length >= needed;
    var pct = progressPercent();
    var selectedDone = !!state.confirmed[state.selectedChallengeId];
    var selectedRequested = isRequested(state.selectedChallengeId);

    var modeText = hasAndCombination() ?
      "Use each required method to verify your login." :
      "Use one method to verify your login.";

    var doneLabels = done.map(function(id){
      var c = challengeById(id);
      return c ? challengeLabel(c) : id;
    });
    var doneText = doneLabels.length ? doneLabels.join(", ") : "No method verified yet";

    var actionHtml = "";
    var codeRow = "";
    var disabled = state.busy ? " disabled" : "";

    if (!enough) {
      if (!selectedDone && selectedRequested) {
        codeRow = "<tr><td class=\"titlefield\">Verification code</td><td><input id=\"tb-code\" type=\"text\" class=\"flat minwidth200\" autocomplete=\"one-time-code\"></td></tr>";
        actionHtml = "<input type=\"button\" class=\"button\" id=\"tb-verify\" value=\"Verify code\"" + disabled + ">";
      } else if (!selectedDone) {
        actionHtml = "<input type=\"button\" class=\"button\" id=\"tb-send\" value=\"Send code\"" + disabled + ">";
      }
    }

    app.innerHTML = ""
      + "<div class=\"tb2fa-progress\">"
      + "  <div class=\"tb2fa-progress-track\"><div class=\"tb2fa-progress-fill\" style=\"width:" + pct + "%\"></div></div>"
      + "  <div class=\"tb2fa-steps\">"
      + "    <span class=\"tb2fa-step done\">Step 1: Credentials entered</span>"
      + "    <span class=\"tb2fa-step " + (enough ? "done" : "pending") + "\">Step 2: Verify login</span>"
      + "  </div>"
      + "</div>"
      + "<div class=\"tb2fa-banner\" style=\"margin-bottom:10px\">" + esc(modeText) + "</div>"
      + "<table class=\"noborder centpercent\">"
      + "<tr><td class=\"titlefield\">Verification method</td><td><select id=\"tb-method\" class=\"flat minwidth300\">" + options + "</select></td></tr>"
      + codeRow
      + "</table>"
      + "<div style=\"margin-top:10px;display:flex;gap:8px;align-items:center\">"
      + actionHtml
      + "  <input type=\"button\" class=\"button button-cancel\" id=\"tb-back\" value=\"Cancel\"" + disabled + ">"
      + "</div>"
      + "<div id=\"tb-notice\" class=\"tb2fa-note\" style=\"margin-top:8px\"></div>"
      + "<div style=\"margin-top:12px\">"
      + "  <strong>Verified:</strong> " + esc(doneText)
      + "</div>";

    byId("tb-method").onchange = function(){
      state.selectedChallengeId = this.value || "";
      setNotice("", false);
      render();
    };
    var sendBtn = byId("tb-send");
    if (sendBtn) sendBtn.onclick = sendCode;
    var verifyBtn = byId("tb-verify");
    if (verifyBtn) verifyBtn.onclick = verifyCode;
    byId("tb-back").onclick = goBackToCard;
  }

  async function sendCode(){
    if (state.busy) return;
    if (!state.selectedChallengeId) {
      setNotice("Select a verification method first.", true);
      return;
    }
    state.busy = true;
    render();
    setNotice("Sending verification code...", false);

    var endpoint = state.ctx.challengeBaseUrl + encodeURIComponent(state.selectedChallengeId);
    try {
      var resp = await fetch(endpoint, {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json"
        },
        body: "{}"
      });

      var data = {};
      try { data = await resp.json(); } catch (e) {}

      if (!resp.ok) {
        var hint = (data && typeof data.hint === "string" && data.hint.length) ? (" - " + data.hint) : "";
        state.busy = false;
        render();
        setNotice("Failed to send code (HTTP " + resp.status + ")" + hint, true);
        return;
      }

      state.requested[state.selectedChallengeId] = true;
      state.busy = false;
      render();
      setNotice("Verification code sent. Check your selected channel.", false);
    } catch (e2) {
      state.busy = false;
      render();
      setNotice("Failed to send code: " + (e2 && e2.message ? e2.message : e2), true);
    }
  }

  async function verifyCode(){
    if (state.busy) return;
    if (!state.selectedChallengeId) {
      setNotice("Select a verification method first.", true);
      return;
    }

    var codeEl = byId("tb-code");
    var tan = codeEl ? (codeEl.value || "").trim() : "";
    if (!tan) {
      setNotice("Enter the verification code.", true);
      return;
    }

    state.busy = true;
    render();
    setNotice("Verifying code...", false);

    var endpoint = state.ctx.challengeBaseUrl + encodeURIComponent(state.selectedChallengeId) + "/confirm";
    try {
      var resp = await fetch(endpoint, {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ tan: tan })
      });

      var data = {};
      try { data = await resp.json(); } catch (e) {}

      if (!(resp.status === 204 || resp.ok)) {
        var hint = (data && typeof data.hint === "string" && data.hint.length) ? (" - " + data.hint) : "";
        state.busy = false;
        render();
        setNotice("Verification failed (HTTP " + resp.status + ")" + hint, true);
        return;
      }

      state.confirmed[state.selectedChallengeId] = true;
      state.requested[state.selectedChallengeId] = true;
      if (codeEl) codeEl.value = "";
      state.busy = false;
      render();

      if (confirmedIds().length >= requiredCount()) {
        setNotice("Code verified. Finalizing login...", false);
        completeLogin();
      } else {
        setNotice("Method verified. Continue with the next required method.", false);
      }
    } catch (e2) {
      state.busy = false;
      render();
      setNotice("Verification failed: " + (e2 && e2.message ? e2.message : e2), true);
    }
  }

  function extractToken(payload){
    if (!payload || typeof payload !== "object") return "";
    if (typeof payload.access_token === "string" && payload.access_token.length) return payload.access_token;
    if (typeof payload.token === "string" && payload.token.length) return payload.token;
    return "";
  }

  function extractExpirationTs(payload){
    if (!payload || !payload.expiration) return null;
    var exp = payload.expiration;
    var tsMax = Math.floor(Date.UTC(2037, 11, 31, 23, 59, 59) / 1000);
    if (exp.t_s === "never") return tsMax;
    if (typeof exp.t_s === "number") return Math.min(exp.t_s, tsMax);
    return null;
  }

  function addHidden(form, name, value){
    var input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = String(value == null ? "" : value);
    form.appendChild(input);
  }

  function submitBackWithToken(token, expirationTs){
    var target = (state.ctx && state.ctx.formAction) ? state.ctx.formAction : fallbackCardUrl;
    var entries = Array.isArray(state.ctx.formData) ? state.ctx.formData : [];
    var skip = { taler_password: 1, talertoken: 1, expiration: 1 };

    var form = document.createElement("form");
    form.method = "POST";
    form.action = target;

    for (var i = 0; i < entries.length; i++) {
      var pair = entries[i];
      if (!Array.isArray(pair) || pair.length < 2) continue;
      var name = String(pair[0]);
      if (skip[name]) continue;
      addHidden(form, name, pair[1]);
    }

    addHidden(form, "talertoken", token);
    addHidden(form, "expiration", (expirationTs === null ? "" : expirationTs));

    clearContext();
    document.body.appendChild(form);
    form.submit();
  }

  async function completeLogin(){
    if (state.busy) return;
    var ids = confirmedIds();
    if (!ids.length) {
      setNotice("Verify at least one method first.", true);
      return;
    }

    if (hasAndCombination() && ids.length < requiredCount()) {
      setNotice("All listed methods must be verified before continuing.", true);
      return;
    }

    state.busy = true;
    render();
    setNotice("Completing login...", false);

    try {
      var resp = await fetch(state.ctx.tokenUrl, {
        method: "POST",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json",
          "Authorization": state.ctx.basicAuth,
          "Taler-Challenge-Ids": ids.join(",")
        },
        body: state.ctx.tokenRequestBody
      });

      var data = {};
      try { data = await resp.json(); } catch (e) {}

      if (resp.status === 202) {
        state.ctx.challengeResponse = data || {};
        saveContext();
        state.busy = false;
        render();
        setNotice("Additional verification is required. Continue with the remaining method(s).", true);
        return;
      }

      if (!resp.ok) {
        var hint = (data && typeof data.hint === "string" && data.hint.length) ? (" - " + data.hint) : "";
        state.busy = false;
        render();
        setNotice("Token endpoint returned HTTP " + resp.status + hint, true);
        return;
      }

      var token = extractToken(data);
      if (!token) {
        state.busy = false;
        render();
        setNotice("Token endpoint succeeded but returned no token field.", true);
        return;
      }

      var expTs = extractExpirationTs(data);
      submitBackWithToken(token, expTs);
    } catch (e2) {
      state.busy = false;
      render();
      setNotice("Error while completing login: " + (e2 && e2.message ? e2.message : e2), true);
    }
  }

  state.ctx = loadContext();
  render();
})();
</script>';

llxFooter();
$db->close();
