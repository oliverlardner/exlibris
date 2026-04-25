(function () {
  const CURRENT_PROJECTS_STORAGE_KEY = "exlibris_current_project_ids";
  const READER_FONT_FAMILY_STORAGE_KEY = "exlibris_reader_font_family";
  const READER_FONT_SIZE_STORAGE_KEY = "exlibris_reader_font_size";

  function qs(selector) {
    return document.querySelector(selector);
  }

  function qsa(selector) {
    return Array.from(document.querySelectorAll(selector));
  }

  function appBase() {
    return (document.body?.dataset?.appBase || "").replace(/\/$/, "");
  }

  function endpoint(path) {
    if (!path.startsWith("/")) return path;
    return `${appBase()}${path}`;
  }

  function readJsonScript(id, fallback) {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try {
      return JSON.parse(el.textContent || "");
    } catch (_) {
      return fallback;
    }
  }

  function adminToken() {
    return localStorage.getItem("exlibris_admin_token") || "";
  }

  function headersWithAuth() {
    const headers = { "Content-Type": "application/json" };
    const token = adminToken();
    if (token) headers["X-Admin-Token"] = token;
    return headers;
  }

  const AI_RUNNER_FRAMES = [
    String.raw`  _o
 /|_]
  / \  [#]`,
    String.raw`   _o
 _/|]
  / \  [#]`,
    String.raw`    o_
   [|\_
   / \  [#]`,
    String.raw`   _o
  [| \_
  / \   [#]`,
  ];

  let globalAiWorkCount = 0;
  let globalAiRunnerTimer = null;
  let globalAiRunnerFrame = 0;

  function isAiTrackedRequestUrl(url) {
    const s = (url || "").toString();
    return s.includes("/api/assistant.php") || s.includes("/api/process.php");
  }

  function globalAiRunnerSetVisible(show) {
    const wrap = qs("#global-ai-activity");
    const pre = qs("#global-ai-activity-ascii");
    if (!wrap || !pre) return;
    if (show) {
      wrap.classList.remove("hidden");
      wrap.setAttribute("aria-hidden", "false");
      globalAiRunnerFrame = 0;
      pre.textContent = AI_RUNNER_FRAMES[0];
      if (globalAiRunnerTimer) window.clearInterval(globalAiRunnerTimer);
      globalAiRunnerTimer = window.setInterval(() => {
        globalAiRunnerFrame = (globalAiRunnerFrame + 1) % AI_RUNNER_FRAMES.length;
        if (pre) pre.textContent = AI_RUNNER_FRAMES[globalAiRunnerFrame];
      }, 120);
    } else {
      wrap.classList.add("hidden");
      wrap.setAttribute("aria-hidden", "true");
      if (globalAiRunnerTimer) {
        window.clearInterval(globalAiRunnerTimer);
        globalAiRunnerTimer = null;
      }
      pre.textContent = "";
    }
  }

  function globalAiRequestStart() {
    globalAiWorkCount += 1;
    if (globalAiWorkCount === 1) {
      globalAiRunnerSetVisible(true);
    }
  }

  function globalAiRequestEnd() {
    globalAiWorkCount = Math.max(0, globalAiWorkCount - 1);
    if (globalAiWorkCount === 0) {
      globalAiRunnerSetVisible(false);
    }
  }

  async function postJson(url, payload) {
    const trackAi = isAiTrackedRequestUrl(url);
    if (trackAi) {
      globalAiRequestStart();
    }
    const transientCodes = new Set([502, 503, 504]);
    let lastError = null;
    try {
      for (let attempt = 0; attempt < 3; attempt += 1) {
        let response = null;
        let data = null;
        try {
          response = await fetch(endpoint(url), {
            method: "POST",
            headers: headersWithAuth(),
            body: JSON.stringify(payload),
          });
        } catch (error) {
          lastError = error;
          if (attempt < 2) {
            await new Promise((resolve) => setTimeout(resolve, 300 * (attempt + 1)));
            continue;
          }
          throw error;
        }
        try {
          data = await response.json();
        } catch (_) {}
        if (response.ok) {
          return data || {};
        }
        const error = new Error(data?.error || `Request failed (${response.status})`);
        lastError = error;
        if (transientCodes.has(response.status) && attempt < 2) {
          await new Promise((resolve) => setTimeout(resolve, 300 * (attempt + 1)));
          continue;
        }
        throw error;
      }
      throw lastError || new Error("Request failed");
    } finally {
      if (trackAi) {
        globalAiRequestEnd();
      }
    }
  }

  function setText(selector, value) {
    const el = qs(selector);
    if (el) el.textContent = value || "";
  }

  function elFromOutSelector(outSelector) {
    if (typeof outSelector === "string") {
      return qs(outSelector);
    }
    return outSelector || null;
  }

  function appendMultilineBlock(parent, text) {
    const div = document.createElement("div");
    div.className = "ai-reading-block";
    const s = String(text || "");
    const lines = s.split("\n");
    lines.forEach((line, i) => {
      if (i) div.appendChild(document.createElement("br"));
      div.appendChild(document.createTextNode(line));
    });
    parent.appendChild(div);
  }

  function appendReadingSummaryToParent(parent, raw) {
    const text = String(raw || "")
      .replace(/\r\n/g, "\n")
      .trim();
    if (!text) return;
    if (!text.includes("\n## ")) {
      appendMultilineBlock(parent, text);
      return;
    }
    const parts = text.split(/\n##\s+/);
    if (!parts || parts.length < 1) {
      appendMultilineBlock(parent, text);
      return;
    }
    const intro = parts[0] ? String(parts[0]).trim() : "";
    if (intro) {
      appendMultilineBlock(parent, intro);
    }
    for (let i = 1; i < parts.length; i++) {
      const block = String(parts[i] || "").trim();
      if (!block) continue;
      const nl = block.indexOf("\n");
      const title = (nl === -1 ? block : block.slice(0, nl)).trim();
      const body = nl === -1 ? "" : block.slice(nl + 1).trim();
      if (title) {
        const h3 = document.createElement("h3");
        h3.className = "ai-reading-h";
        h3.textContent = title;
        parent.appendChild(h3);
      }
      if (body) {
        appendMultilineBlock(parent, body);
      }
    }
  }

  function renderSourceAssistantOutput(action, data, outSelector) {
    const out = elFromOutSelector(outSelector);
    if (!out) return;
    out.innerHTML = "";
    if (!data || data.ok === false) {
      const p = document.createElement("p");
      p.className = "muted";
      p.textContent = (data && data.error) || "Request failed.";
      out.appendChild(p);
      return;
    }
    const heading = (text) => {
      const h3 = document.createElement("h3");
      h3.textContent = text;
      return h3;
    };
    const para = (text, className) => {
      const p = document.createElement("p");
      if (className) p.className = className;
      p.textContent = text;
      return p;
    };
    const addStringList = (title, items) => {
      if (!Array.isArray(items) || !items.length) return;
      out.appendChild(heading(title));
      const ul = document.createElement("ul");
      items.forEach((t) => {
        const li = document.createElement("li");
        li.textContent = String(t || "");
        ul.appendChild(li);
      });
      out.appendChild(ul);
    };
    if (action === "annotate_source") {
      const ann = data.annotation || {};
      const guideWrap = document.createElement("div");
      guideWrap.className = "ai-reading-guide";
      if (ann.summary) {
        out.appendChild(heading("Reading guide"));
        appendReadingSummaryToParent(guideWrap, ann.summary);
        out.appendChild(guideWrap);
      }
      addStringList("Key claims", ann.key_claims);
      addStringList("Methods / approach", ann.methods);
      addStringList("Limitations", ann.limitations);
      out.appendChild(para("Saved on this source (see the reading guide on the page after refresh).", "muted"));
      return;
    }
    if (action === "source_quality") {
      const q = data.quality || {};
      const scoreText = q.score != null && q.score !== "" ? String(q.score) : "—";
      out.appendChild(para(`Score: ${scoreText} — ${q.reason || ""}`));
      return;
    }
    if (action === "citation_qa") {
      const qa = data.qa || {};
      out.appendChild(para(String(qa.citation || "")));
      const issues = Array.isArray(qa.issues) ? qa.issues : [];
      if (issues.length) {
        out.appendChild(heading("Issues"));
        const ul = document.createElement("ul");
        issues.forEach((i) => {
          const li = document.createElement("li");
          li.textContent = String(i);
          ul.appendChild(li);
        });
        out.appendChild(ul);
      }
      out.appendChild(para(qa.pass ? "Pass" : "Needs attention", "muted"));
      return;
    }
    if (action === "similar_sources") {
      const results = data.results || [];
      if (!results.length) {
        out.appendChild(para("No similar sources found.", "muted"));
        return;
      }
      out.appendChild(heading("Similar sources"));
      results.forEach((item) => {
        const s = (item && item.source) || {};
        const id = Number(s.id || 0);
        const row = document.createElement("div");
        if (id) {
          const a = document.createElement("a");
          a.href = `/source.php?id=${id}`;
          a.className = "reader-source-ref";
          a.textContent = s.title || `Source #${id}`;
          row.appendChild(a);
        } else {
          row.textContent = s.title || "Unknown";
        }
        if (item && item.score != null) {
          row.appendChild(document.createTextNode(` (${Number(item.score).toFixed(3)})`));
        }
        out.appendChild(row);
      });
      return;
    }
    const pre = document.createElement("pre");
    pre.className = "muted";
    pre.textContent = JSON.stringify(data, null, 2);
    out.appendChild(pre);
  }

  async function runSourceAssistant(action, payload, outSelector) {
    const data = await postJson("/api/assistant.php", { action, ...payload });
    renderSourceAssistantOutput(action, data, outSelector);
  }

  function renderSemanticResults(results) {
    const panel = qs("#semantic-results");
    if (!panel) return;
    panel.innerHTML = "";

    const items = Array.isArray(results) ? results : [];
    if (!items.length) {
      panel.textContent = "No semantic matches yet.";
      return;
    }

    items.forEach((item) => {
      const source = item && typeof item === "object" ? (item.source || {}) : {};
      const score = Number(item?.score || 0);
      const title = String(source.title || "").trim() || "Untitled source";
      const sourceId = Number(source.id || 0);
      const year = String(source.year || "").trim();
      const type = String(source.type || "").trim();
      const authors = Array.isArray(source.authors) ? source.authors.filter(Boolean) : [];
      const summaryBits = [];
      if (authors.length) summaryBits.push(authors.slice(0, 3).join(", "));
      if (year) summaryBits.push(year);
      if (type) summaryBits.push(type);

      const itemEl = document.createElement("article");
      itemEl.className = "semantic-result-item";

      const titleEl = document.createElement(sourceId > 0 ? "a" : "span");
      titleEl.className = "semantic-result-title";
      titleEl.textContent = title;
      if (sourceId > 0) {
        titleEl.href = `/source.php?id=${sourceId}`;
      }
      itemEl.appendChild(titleEl);

      const meta = document.createElement("div");
      meta.className = "meta";
      const scoreChip = document.createElement("span");
      scoreChip.textContent = `Score ${score.toFixed(3)}`;
      meta.appendChild(scoreChip);
      summaryBits.forEach((bit) => {
        const metaBit = document.createElement("span");
        metaBit.textContent = bit;
        meta.appendChild(metaBit);
      });
      itemEl.appendChild(meta);

      const provenance = String(source.provenance_summary || "").trim();
      const notes = String(source.notes || "").trim();
      const bodyText = String(source.body_text || "").trim();
      const excerpt = provenance || notes || (bodyText ? bodyText.slice(0, 220) + (bodyText.length > 220 ? "..." : "") : "");
      if (excerpt) {
        const summary = document.createElement("p");
        summary.className = "semantic-result-summary";
        summary.textContent = excerpt;
        itemEl.appendChild(summary);
      }

      panel.appendChild(itemEl);
    });
  }

  function setStatus(value, isError) {
    const el = qs("#app-status");
    if (!el) return;
    el.textContent = value || "";
    el.classList.toggle("error", Boolean(isError));
  }

  function getCurrentProjectIds() {
    try {
      const parsed = JSON.parse(localStorage.getItem(CURRENT_PROJECTS_STORAGE_KEY) || "[]");
      return Array.isArray(parsed) ? parsed.map((id) => Number(id || 0)).filter((id) => id > 0) : [];
    } catch (_) {
      return [];
    }
  }

  function setCurrentProjectIds(ids) {
    const safeIds = Array.from(new Set((Array.isArray(ids) ? ids : []).map((id) => Number(id || 0)).filter((id) => id > 0)));
    localStorage.setItem(CURRENT_PROJECTS_STORAGE_KEY, JSON.stringify(safeIds));
  }

  function readerFontFamilyToCss(value) {
    switch (String(value || "").trim()) {
      case "arial":
        return "Arial, Helvetica, sans-serif";
      case "georgia":
        return "Georgia, serif";
      case "times":
        return "\"Times New Roman\", Times, serif";
      case "ibm-mono":
        return "IBM, monospace";
      case "helvetica":
      default:
        return "\"Helvetica Neue\", Helvetica, Arial, sans-serif";
    }
  }

  function getReaderFontFamily() {
    return localStorage.getItem(READER_FONT_FAMILY_STORAGE_KEY) || "helvetica";
  }

  function setReaderFontFamily(value) {
    localStorage.setItem(READER_FONT_FAMILY_STORAGE_KEY, String(value || "helvetica"));
  }

  function getReaderFontSize() {
    const raw = Number(localStorage.getItem(READER_FONT_SIZE_STORAGE_KEY) || 12);
    return Number.isFinite(raw) && raw >= 8 && raw <= 48 ? raw : 12;
  }

  function setReaderFontSize(value) {
    const size = Number(value || 12);
    localStorage.setItem(READER_FONT_SIZE_STORAGE_KEY, String(Number.isFinite(size) ? size : 12));
  }

  function currentProjectOptions() {
    const projects = readJsonScript("current-projects-data", []);
    return Array.isArray(projects)
      ? projects
          .map((project) => ({
            id: Number(project?.id || 0),
            name: String(project?.name || "").trim(),
          }))
          .filter((project) => project.id > 0 && project.name)
      : [];
  }

  function projectLookupFromHeader() {
    const lookup = new Map();
    currentProjectOptions().forEach((project) => {
      lookup.set(project.id, project.name);
    });
    return lookup;
  }

  function formatTraceLines(trace) {
    if (!Array.isArray(trace) || trace.length === 0) return [];
    return trace.map((item) => `- ${item.step || "step"}: ${(item.status || "info").toUpperCase()}${item.detail ? ` - ${item.detail}` : ""}`);
  }

  function withBusyButton(button, busyText, callback) {
    if (!button) return callback();
    const baseText = button.textContent;
    button.textContent = busyText;
    button.disabled = true;
    const done = () => {
      button.disabled = false;
      button.textContent = baseText;
    };
    try {
      const result = callback();
      if (result && typeof result.then === "function") {
        return result.finally(done);
      }
      done();
      return result;
    } catch (error) {
      done();
      throw error;
    }
  }

  async function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const temp = document.createElement("textarea");
    temp.value = value;
    temp.setAttribute("readonly", "true");
    temp.style.position = "fixed";
    temp.style.opacity = "0";
    document.body.appendChild(temp);
    temp.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(temp);
    if (!ok) throw new Error("Copy failed");
  }

  function parseSourceForm(form) {
    const data = Object.fromEntries(new FormData(form).entries());
    const projectNames = String(data.project_names || "")
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);
    return {
      id: Number(data.id || 0),
      type: data.type || "other",
      title: data.title || "",
      authors: String(data.authors || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean),
      year: data.year || "",
      publisher: data.publisher || "",
      journal: data.journal || "",
      volume: data.volume || "",
      issue: data.issue || "",
      pages: data.pages || "",
      doi: data.doi || "",
      isbn: data.isbn || "",
      url: data.url || "",
      accessed_at: data.accessed_at || "",
      notes: data.notes || "",
      raw_input: data.raw_input || "",
      provenance_summary: data.provenance_summary || "",
      body_text: data.body_text || "",
      body_source: data.body_source || "",
      lookup_trace: (() => {
        try {
          const parsed = JSON.parse(String(data.lookup_trace_json || "[]"));
          return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
          return [];
        }
      })(),
      project_names: projectNames,
    };
  }

  function fillSourceForm(source) {
    const form = qs("#source-form");
    if (!form) return;
    form.elements.id.value = source.id || "";
    form.elements.type.value = source.type || "other";
    form.elements.title.value = source.title || "";
    form.elements.authors.value = Array.isArray(source.authors) ? source.authors.join(", ") : "";
    form.elements.year.value = source.year || "";
    form.elements.publisher.value = source.publisher || "";
    form.elements.journal.value = source.journal || "";
    form.elements.volume.value = source.volume || "";
    form.elements.issue.value = source.issue || "";
    form.elements.pages.value = source.pages || "";
    form.elements.doi.value = source.doi || "";
    form.elements.isbn.value = source.isbn || "";
    form.elements.url.value = source.url || "";
    if (form.elements.accessed_at) form.elements.accessed_at.value = source.accessed_at || "";
    form.elements.notes.value = source.notes || "";
    if (form.elements.body_text) form.elements.body_text.value = source.body_text || "";
    if (form.elements.body_source) form.elements.body_source.value = source.body_source || "";
    if (form.elements.provenance_summary) form.elements.provenance_summary.value = source.provenance_summary || "";
    if (form.elements.lookup_trace_json) {
      const trace = Array.isArray(source.lookup_trace) ? source.lookup_trace : [];
      form.elements.lookup_trace_json.value = JSON.stringify(trace);
    }
    if (form.elements.project_names) {
      form.elements.project_names.value = Array.isArray(source.project_names) ? source.project_names.join(", ") : "";
    }
    form.elements.raw_input.value = source.raw_input || "";
    form.classList.remove("hidden");
  }

  function renderSuggestions(suggestions) {
    const panel = qs("#suggestions-panel");
    const list  = qs("#suggestions-list");
    if (!panel || !list) return;

    if (!Array.isArray(suggestions) || suggestions.length === 0) {
      panel.classList.add("hidden");
      list.innerHTML = "";
      return;
    }

    list.innerHTML = "";
    suggestions.forEach((s) => {
      const row = document.createElement("div");
      row.className = "row";

      const meta = document.createElement("span");
      const authorStr = Array.isArray(s.authors) ? s.authors.slice(0, 2).join(", ") : "";
      const parts = [s.title, authorStr, s.year, s.publisher].filter(Boolean);
      meta.textContent = parts.join(" — ");
      meta.style.flex = "1";

      const btn = document.createElement("button");
      btn.className = "btn btn-load";
      btn.type = "button";
      btn.textContent = "Use";
      btn.addEventListener("click", () => {
        fillSourceForm(s);
        panel.classList.add("hidden");
        setStatus(`Loaded: ${s.title || "selected source"}.`);
      });

      row.appendChild(meta);
      row.appendChild(btn);
      list.appendChild(row);
    });

    panel.classList.remove("hidden");
  }

  async function processDumpInput() {
    const input = (qs("#dump-input")?.value || "").trim();
    if (!input) return setStatus("Please paste input first.", true);
    try {
      setStatus("Processing...");
      const data = await postJson("/api/process.php", { input });
      fillSourceForm(data.source || {});
      renderSuggestions(data.suggestions || []);
      const suggCount = (data.suggestions || []).length;
      const baseMsg = `Parsed as ${data.input_type || "unknown"}.`;
      setStatus(suggCount > 0 ? `${baseMsg} ${suggCount} suggestion${suggCount > 1 ? "s" : ""} below.` : baseMsg);
      renderLookupTrace(data.lookup_trace || []);
    } catch (error) {
      setStatus(error.message || "Processing failed.", true);
    }
  }

  function renderLookupTrace(trace) {
    const wrap = qs("#lookup-feedback");
    const body = qs("#lookup-feedback-body");
    if (!wrap || !body) return;
    if (!Array.isArray(trace) || trace.length === 0) {
      wrap.classList.add("hidden");
      body.textContent = "";
      return;
    }
    body.textContent = formatTraceLines(trace).join("\n");
    wrap.classList.remove("hidden");
  }

  async function saveSource(event) {
    event.preventDefault();
    // Capture the form before awaiting — some browsers (Safari in particular)
    // null out event.currentTarget after the handler yields, which would throw
    // "null is not an object (evaluating 'event.currentTarget.elements')".
    const form = event.currentTarget || event.target;
    try {
      setStatus("Saving...");
      const source = parseSourceForm(form);
      const data = await postJson("/api/save.php", { source });
      if (form && form.elements && form.elements.id) {
        form.elements.id.value = data.id || "";
      }
      setStatus("Saved.");
    } catch (error) {
      setStatus(error.message || "Save failed.", true);
    }
  }

  function wireDropInput() {
    const input = qs("#dump-input");
    if (!input) return;
    const overlay = qs("#global-dropzone");
    const setActive = (active) => {
      if (overlay) overlay.classList.toggle("hidden", !active);
    };
    window.addEventListener("dragover", (e) => {
      e.preventDefault();
      setActive(true);
    });
    window.addEventListener("dragleave", (e) => {
      e.preventDefault();
      if (e.relatedTarget === null) setActive(false);
    });
    window.addEventListener("drop", async (e) => {
      e.preventDefault();
      setActive(false);
      const dt = e.dataTransfer;
      const files = Array.from(dt?.files || []);
      if (files.length) {
        const text = await files[0].text();
        input.value = text;
        setStatus(`Loaded ${files[0].name}.`);
        return;
      }
      const dropped = (dt?.getData("text/uri-list") || dt?.getData("text/plain") || "").trim();
      if (dropped) {
        input.value = dropped;
        setStatus("Loaded dropped link.");
      }
    });
  }

  function wireSearchAndCards() {
    const input = qs("#search-input");
    const collectionFilter = qs("#collection-filter");
    const composeReaderLink = qs("#compose-reader-link");

    const updateBibliography = () => {
      const lines = qsa("[data-source-card]")
        .filter((card) => !card.classList.contains("hidden"))
        .map((card) => String(card.getAttribute("data-citation") || "").trim())
        .filter(Boolean)
        .sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
      const text = lines.join("\n");
      const textarea = qs("#bibliography-text");
      if (textarea) textarea.value = text;
      const copy = qs("#bibliography-copy");
      if (copy) copy.setAttribute("data-copy-citation", text);
    };

    const updateComposeReaderLink = () => {
      if (!composeReaderLink) return;
      const visibleIds = qsa("[data-source-card]")
        .filter((card) => !card.classList.contains("hidden"))
        .map((card) => Number(card.getAttribute("data-source-id") || 0))
        .filter((id) => id > 0);
      const params = new URLSearchParams();
      if (visibleIds.length) params.set("ids", visibleIds.join(","));
      const ctx = String(input?.value || "").trim();
      if (ctx) params.set("ctx", ctx);
      const query = params.toString();
      composeReaderLink.href = query ? `/reader.php?${query}` : "/reader.php";
    };

    const applyFilters = () => {
      const term = (input?.value || "").toLowerCase().trim();
      const collectionId = String(collectionFilter?.value || "").trim();
      qsa("[data-source-card]").forEach((card) => {
        const hay = (card.getAttribute("data-search") || "").toLowerCase();
        const collectionIds = String(card.getAttribute("data-collection-ids") || "")
          .split(",")
          .map((s) => s.trim())
          .filter(Boolean);
        const matchesTerm = !term || hay.includes(term);
        const matchesCollection = !collectionId || collectionIds.includes(collectionId);
        card.classList.toggle("hidden", !(matchesTerm && matchesCollection));
      });
      updateBibliography();
      updateComposeReaderLink();
    };

    if (input) {
      input.addEventListener("input", applyFilters);
    }
    if (collectionFilter) {
      collectionFilter.addEventListener("change", applyFilters);
    }

    qsa("[data-copy-citation]").forEach((button) => {
      const baseText = button.textContent.trim();
      const isIconButton = !baseText && button.querySelector("svg");
      const baseTitle = button.getAttribute("title") || "";
      const baseLabel = button.getAttribute("aria-label") || baseTitle;
      button.addEventListener("click", async () => {
        try {
          await copyText(button.getAttribute("data-copy-citation") || "");
          if (isIconButton) {
            button.setAttribute("title", "Copied");
            if (baseLabel) button.setAttribute("aria-label", "Copied");
            setTimeout(() => {
              if (baseTitle) button.setAttribute("title", baseTitle);
              else button.removeAttribute("title");
              if (baseLabel) button.setAttribute("aria-label", baseLabel);
            }, 1200);
          } else {
            const prev = baseText;
            button.textContent = "Copied";
            setTimeout(() => (button.textContent = prev), 1200);
          }
        } catch (_) {
          if (isIconButton) {
            button.setAttribute("title", "Copy failed");
            if (baseLabel) button.setAttribute("aria-label", "Copy failed");
            setTimeout(() => {
              if (baseTitle) button.setAttribute("title", baseTitle);
              if (baseLabel) button.setAttribute("aria-label", baseLabel);
            }, 1500);
          } else {
            const prev = baseText;
            button.textContent = "Copy failed";
            setTimeout(() => (button.textContent = prev), 1500);
          }
        }
      });
    });

    qsa("[data-delete-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        if (!confirm("Delete this source?")) return;
        try {
          await postJson("/api/delete.php", { id: Number(button.getAttribute("data-delete-id") || 0) });
          location.reload();
        } catch (error) {
          alert(error.message || "Delete failed.");
        }
      });
    });

    qsa("[data-zotero-push-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        const sourceId = Number(button.getAttribute("data-zotero-push-id") || 0);
        if (!sourceId) return;
        try {
          button.textContent = "Pushing...";
          const data = await postJson("/api/zotero.php", { mode: "push_one", source_id: sourceId });
          setText("#assistant-panel-output", JSON.stringify(data, null, 2));
          // Per-source results can still contain a failure payload even on a
          // 200 response (e.g. collection creation failed). Surface those.
          const firstResult = Array.isArray(data.results) ? data.results[0] : null;
          if (firstResult && firstResult.status === "error") {
            const msg = firstResult.error || "Push failed";
            button.textContent = "Push failed";
            setTimeout(() => (button.textContent = "Push Zotero"), 2000);
            alert("Zotero push failed: " + msg);
            return;
          }
          button.textContent = "Pushed";
          setTimeout(() => (button.textContent = "Push Zotero"), 1200);
        } catch (error) {
          button.textContent = "Push failed";
          setTimeout(() => (button.textContent = "Push Zotero"), 2000);
          alert("Zotero push failed: " + (error.message || "unknown error"));
        }
      });
    });

    updateBibliography();
    updateComposeReaderLink();
  }

  function wireThemeAndFormat() {
    const theme = qs("#theme-toggle");
    if (theme) {
      theme.addEventListener("click", async () => {
        const current = document.documentElement.getAttribute("data-theme-mode") || "auto";
        const order = ["auto", "light", "dark"];
        const next = order[(order.indexOf(current) + 1) % order.length];
        const data = await postJson("/api/settings.php", { theme_mode: next });
        document.documentElement.setAttribute("data-theme-mode", data.theme_mode || next);
        theme.textContent = `Theme: ${(data.theme_mode || next).toUpperCase()}`;
      });
    }

    const format = qs("#citation-format");
    if (format) format.addEventListener("change", () => postJson("/api/settings.php", { citation_format: format.value }).then(() => location.reload()));
    const includePages = qs("#include-pages");
    if (includePages) includePages.addEventListener("change", () => postJson("/api/settings.php", { include_pages_in_citations: includePages.value }).then(() => location.reload()));
  }

  function wireCurrentProjects() {
    const chips = qs("#current-project-chips");
    const input = qs("#current-project-input");
    const dataScript = qs("#current-projects-data");
    const datalist = qs("#current-project-options");
    if (!chips || !input || !dataScript || !datalist) return;

    let options = currentProjectOptions();

    const syncDataScript = () => {
      dataScript.textContent = JSON.stringify(options);
    };

    const renderDatalist = () => {
      datalist.innerHTML = "";
      options
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: "base" }))
        .forEach((project) => {
          const option = document.createElement("option");
          option.value = project.name;
          datalist.appendChild(option);
        });
    };

    const renderChips = () => {
      chips.innerHTML = "";
      const lookup = new Map(options.map((project) => [project.id, project.name]));
      const selectedIds = getCurrentProjectIds();
      if (!selectedIds.length) {
        const empty = document.createElement("span");
        empty.className = "muted";
        empty.textContent = "none";
        chips.appendChild(empty);
        return;
      }
      selectedIds.forEach((id) => {
        const name = lookup.get(id) || `Project ${id}`;
        const chip = document.createElement("button");
        chip.type = "button";
        chip.className = "header-project-chip";
        chip.setAttribute("data-project-id", String(id));
        chip.innerHTML = `<span>${name}</span><span class="header-project-chip-remove" aria-hidden="true">x</span>`;
        chip.title = `Remove ${name}`;
        chip.addEventListener("click", () => {
          setCurrentProjectIds(getCurrentProjectIds().filter((value) => value !== id));
          renderChips();
        });
        chips.appendChild(chip);
      });
    };

    const addProjectByName = async (rawName) => {
      const name = String(rawName || "").trim();
      if (!name) return;

      const existing = options.find((project) => project.name.toLowerCase() === name.toLowerCase());
      if (existing) {
        setCurrentProjectIds(getCurrentProjectIds().concat(existing.id));
        renderChips();
        input.value = "";
        return;
      }

      try {
        const data = await postJson("/api/projects.php", { name });
        const project = data?.project || {};
        const projectId = Number(project.id || 0);
        const projectName = String(project.name || "").trim();
        if (!projectId || !projectName) {
          throw new Error("Project creation returned invalid data.");
        }
        options.push({ id: projectId, name: projectName });
        options = Array.from(new Map(options.map((projectItem) => [projectItem.id, projectItem])).values());
        syncDataScript();
        renderDatalist();
        setCurrentProjectIds(getCurrentProjectIds().concat(projectId));
        renderChips();
        input.value = "";
        setStatus(`Current project added: ${projectName}.`);
      } catch (error) {
        setStatus(error.message || "Could not create project.", true);
      }
    };

    const commitInput = async () => {
      const value = String(input.value || "").trim().replace(/,$/, "").trim();
      if (!value) return;
      await addProjectByName(value);
    };

    renderDatalist();
    renderChips();

    input.addEventListener("keydown", async (event) => {
      if (event.key !== "Enter" && event.key !== "Tab" && event.key !== ",") return;
      event.preventDefault();
      await commitInput();
    });

    input.addEventListener("blur", async () => {
      await commitInput();
    });
  }

  function wirePdfActions() {
    const AI_CLEANUP_CONFIRM_THRESHOLD = 150000;

    qsa("[data-pdf-open-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        const sourceId = Number(button.getAttribute("data-pdf-open-id") || 0);
        if (!sourceId) return;
        await withBusyButton(button, "Opening...", async () => {
          try {
            await postJson("/api/pdf.php", { action: "open", id: sourceId });
            setStatus("Opened PDF in Finder.");
          } catch (error) {
            setStatus(error.message || "Could not open PDF.", true);
          }
        });
      });
    });

    qsa("[data-pdf-extract-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        const sourceId = Number(button.getAttribute("data-pdf-extract-id") || 0);
        if (!sourceId) return;
        await withBusyButton(button, "Extracting...", async () => {
          try {
            const data = await postJson("/api/pdf.php", { action: "extract", id: sourceId });
            const chars = Number(data?.chars || 0);
            setStatus(`Extracted ${chars.toLocaleString()} characters from PDF.`);
            window.setTimeout(() => location.reload(), 500);
          } catch (error) {
            setStatus(error.message || "PDF extraction failed.", true);
          }
        });
      });
    });

    qsa("[data-body-reformat-id]").forEach((button) => {
      button.addEventListener("click", async () => {
        const sourceId = Number(button.getAttribute("data-body-reformat-id") || 0);
        const bodyChars = Number(button.getAttribute("data-body-chars") || 0);
        if (!sourceId) return;
        if (bodyChars >= AI_CLEANUP_CONFIRM_THRESHOLD) {
          const wantsCleanup = window.confirm(
            `This extracted text is very long (${bodyChars.toLocaleString()} characters), so AI cleanup may take longer and cost more. Continue?`
          );
          if (!wantsCleanup) return;
        }
        await withBusyButton(button, "Cleaning...", async () => {
          try {
            const data = await postJson("/api/assistant.php", { action: "reformat_body_text", source_id: sourceId });
            const chars = Number(data?.body_chars || 0);
            const summary = String(data?.reformatted?.change_summary || "").trim();
            setStatus(summary ? `Saved AI-cleaned text (${chars.toLocaleString()} chars). ${summary}` : `Saved AI-cleaned text (${chars.toLocaleString()} chars).`);
            window.setTimeout(() => location.reload(), 500);
          } catch (error) {
            setStatus(error.message || "AI text cleanup failed.", true);
          }
        });
      });
    });
  }

  function markdownSegmentsText(segments) {
    return segments.map((segment) => String(segment.text || "")).join("");
  }

  function parseInlineMarkdownViewer(text, classes = [], href = "") {
    const value = String(text || "");
    if (!value) return [];

    const candidates = [
      { type: "link", match: /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/.exec(value) },
      { type: "code", match: /`([^`]+)`/.exec(value) },
      { type: "strong", match: /\*\*([^\n]+?)\*\*/.exec(value) },
    ].filter((candidate) => candidate.match);

    if (!candidates.length) {
      return [{ text: value, classes: classes.slice(), href }];
    }

    candidates.sort((left, right) => left.match.index - right.match.index);
    const first = candidates[0];
    const match = first.match;
    const out = [];
    const start = Number(match.index || 0);
    const before = value.slice(0, start);
    if (before) {
      out.push({ text: before, classes: classes.slice(), href });
    }

    if (first.type === "link") {
      out.push(...parseInlineMarkdownViewer(match[1], classes.slice(), match[2]));
    } else if (first.type === "code") {
      out.push({ text: match[1], classes: classes.concat("viewer-md-inline-code"), href });
    } else if (first.type === "strong") {
      out.push(...parseInlineMarkdownViewer(match[1], classes.concat("viewer-md-strong"), href));
    }

    const after = value.slice(start + match[0].length);
    if (after) {
      out.push(...parseInlineMarkdownViewer(after, classes.slice(), href));
    }
    return out;
  }

  function createViewerMarkdownBlock(className, text, options = {}) {
    const segments = parseInlineMarkdownViewer(text);
    return {
      className,
      marker: options.marker || "",
      segments,
      text: markdownSegmentsText(segments),
    };
  }

  function hasViewerMarkdownSyntax(text) {
    const value = String(text || "");
    return /(^|\n)\s{0,3}(#{1,6})\s+\S/.test(value)
      || /(^|\n)\s*[-*+]\s+\S/.test(value)
      || /(^|\n)\s*\d+\.\s+\S/.test(value)
      || /(^|\n)\s{0,3}>\s*\S/.test(value)
      || /(^|\n)\s*```/.test(value)
      || /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/.test(value)
      || /\*\*([^\n]+?)\*\*/.test(value)
      || /`([^`]+)`/.test(value);
  }

  function parseViewerMarkdown(rawText) {
    const normalized = String(rawText || "").replace(/\r\n/g, "\n").replace(/\r/g, "\n");
    if (!hasViewerMarkdownSyntax(normalized)) {
      const fallback = createViewerMarkdownBlock("viewer-md-block viewer-md-paragraph", normalized);
      return { blocks: [fallback], plainText: fallback.text };
    }
    const lines = normalized.split("\n");
    const blocks = [];
    let paragraphLines = [];
    let codeLines = [];
    let inCodeFence = false;

    const flushParagraph = () => {
      if (!paragraphLines.length) return;
      const paragraph = paragraphLines
        .map((line) => line.trim())
        .filter(Boolean)
        .join(" ");
      if (paragraph) {
        blocks.push(createViewerMarkdownBlock("viewer-md-block viewer-md-paragraph", paragraph));
      }
      paragraphLines = [];
    };

    const flushCodeBlock = () => {
      blocks.push({
        className: "viewer-md-block viewer-md-code-block",
        marker: "",
        segments: [{ text: codeLines.join("\n"), classes: ["viewer-md-code-text"], href: "" }],
        text: codeLines.join("\n"),
      });
      codeLines = [];
    };

    lines.forEach((line) => {
      if (/^\s*```/.test(line)) {
        flushParagraph();
        if (inCodeFence) {
          flushCodeBlock();
          inCodeFence = false;
        } else {
          inCodeFence = true;
          codeLines = [];
        }
        return;
      }

      if (inCodeFence) {
        codeLines.push(line);
        return;
      }

      if (/^\s*$/.test(line)) {
        flushParagraph();
        return;
      }

      let match = line.match(/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/);
      if (match) {
        flushParagraph();
        blocks.push(createViewerMarkdownBlock(`viewer-md-block viewer-md-heading viewer-md-heading-${Math.min(6, match[1].length)}`, match[2]));
        return;
      }

      match = line.match(/^\s{0,3}>\s?(.*)$/);
      if (match) {
        flushParagraph();
        blocks.push(createViewerMarkdownBlock("viewer-md-block viewer-md-blockquote", match[1]));
        return;
      }

      match = line.match(/^\s*[-*+]\s+(.*)$/);
      if (match) {
        flushParagraph();
        blocks.push(createViewerMarkdownBlock("viewer-md-block viewer-md-list-item", match[1], { marker: "•" }));
        return;
      }

      match = line.match(/^\s*(\d+)\.\s+(.*)$/);
      if (match) {
        flushParagraph();
        blocks.push(createViewerMarkdownBlock("viewer-md-block viewer-md-list-item viewer-md-ordered-item", match[2], { marker: match[1] + "." }));
        return;
      }

      paragraphLines.push(line);
    });

    flushParagraph();
    if (inCodeFence) {
      flushCodeBlock();
    }

    return {
      blocks,
      plainText: blocks.map((block) => block.text).join("\n"),
    };
  }

  function appendStyledViewerText(container, text, classes, href) {
    if (!text) return;
    if (href) {
      const link = document.createElement("a");
      link.href = href;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.className = "viewer-md-link";
      classes.forEach((className) => link.classList.add(className));
      link.textContent = text;
      container.appendChild(link);
      return;
    }

    if (Array.isArray(classes) && classes.length) {
      const span = document.createElement("span");
      classes.forEach((className) => span.classList.add(className));
      span.textContent = text;
      container.appendChild(span);
      return;
    }

    container.appendChild(document.createTextNode(text));
  }

  function appendViewerSegmentWithHighlights(container, segment, startOffset, notes, activeNoteId, onActivateNote) {
    const segmentText = String(segment?.text || "");
    if (!segmentText) return;

    let localCursor = 0;
    while (localCursor < segmentText.length) {
      const absoluteOffset = startOffset + localCursor;
      const overlappingNote = notes.find((note) => {
        const noteStart = Number(note.start_offset || 0);
        const noteEnd = Number(note.end_offset || 0);
        return noteEnd > absoluteOffset && noteStart < startOffset + segmentText.length;
      });

      if (!overlappingNote) {
        appendStyledViewerText(container, segmentText.slice(localCursor), segment.classes || [], segment.href || "");
        return;
      }

      const noteStart = Number(overlappingNote.start_offset || 0);
      const noteEnd = Number(overlappingNote.end_offset || 0);
      if (noteStart > absoluteOffset) {
        const plainChunkLength = Math.min(segmentText.length - localCursor, noteStart - absoluteOffset);
        appendStyledViewerText(container, segmentText.slice(localCursor, localCursor + plainChunkLength), segment.classes || [], segment.href || "");
        localCursor += plainChunkLength;
        continue;
      }

      const highlightedLength = Math.min(segmentText.length - localCursor, noteEnd - absoluteOffset);
      const mark = document.createElement("mark");
      mark.className = "viewer-highlight";
      const noteId = Number(overlappingNote.id || 0);
      if (noteId === activeNoteId) {
        mark.classList.add("is-active");
      }
      mark.setAttribute("data-note-id", String(noteId));
      mark.addEventListener("click", () => onActivateNote(noteId));
      appendStyledViewerText(mark, segmentText.slice(localCursor, localCursor + highlightedLength), segment.classes || [], segment.href || "");
      container.appendChild(mark);
      localCursor += highlightedLength;
    }
  }

  function wireAnnotationViewer() {
    const panel = qs("#viewer-text-panel");
    const stage = qs("#viewer-reading-stage");
    const rail = qs("#viewer-annotations-rail");
    const notesLayer = qs("#viewer-notes-layer");
    const selectionCard = qs("#viewer-selection-card");
    if (!panel || !stage || !rail || !notesLayer || !selectionCard) return;

    const source = readJsonScript("viewer-source-data", {});
    const initialNotes = readJsonScript("viewer-notes-data", []);
    const markdownDoc = parseViewerMarkdown(String(source?.body_text || ""));
    const text = markdownDoc.plainText;
    const selectedQuote = qs("#viewer-selected-quote");
    const noteInput = qs("#viewer-note-text");
    const tagsInput = qs("#viewer-note-tags");
    const saveBtn = qs("#viewer-note-save");
    const clearBtn = qs("#viewer-note-clear");
    const noteCount = qs("#viewer-note-count");
    const fontFamilySelect = qs("#viewer-font-family");
    const fontSizeSelect = qs("#viewer-font-size");
    let notes = Array.isArray(initialNotes) ? initialNotes.slice() : [];
    let selectionState = null;
    let activeNoteId = 0;
    let renderQueued = false;

    const applyReaderTypography = () => {
      const familyValue = getReaderFontFamily();
      const sizeValue = getReaderFontSize();
      if (fontFamilySelect) {
        fontFamilySelect.value = familyValue;
      }
      if (fontSizeSelect) {
        fontSizeSelect.value = String(sizeValue);
      }
      panel.style.fontFamily = readerFontFamilyToCss(familyValue);
      panel.style.fontSize = `${sizeValue}px`;
      queueAnnotationLayout();
    };

    const clearBrowserSelection = () => {
      const selection = window.getSelection();
      if (selection) selection.removeAllRanges();
    };

    const clearDraft = () => {
      selectionState = null;
      activeNoteId = 0;
      clearBrowserSelection();
      if (selectedQuote) selectedQuote.value = "";
      if (noteInput) noteInput.value = "";
      if (tagsInput) tagsInput.value = "";
      selectionCard.classList.add("hidden");
      renderViewer();
    };

    const textLength = (value) => Array.from(String(value || "")).length;
    const textSlice = (value, start, end) => Array.from(String(value || "")).slice(start, end).join("");

    const getRangeOffset = (container, offset) => {
      const range = document.createRange();
      range.selectNodeContents(panel);
      range.setEnd(container, offset);
      return textLength(range.toString());
    };

    const updateSelectionState = () => {
      const selection = window.getSelection();
      if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
      const range = selection.getRangeAt(0);
      if (!panel.contains(range.commonAncestorContainer)) return;
      const startOffset = getRangeOffset(range.startContainer, range.startOffset);
      const endOffset = getRangeOffset(range.endContainer, range.endOffset);
      if (endOffset <= startOffset) return;
      const quote = textSlice(text, startOffset, endOffset).trim();
      if (!quote) return;
      const rangeRect = range.getBoundingClientRect();
      const stageRect = stage.getBoundingClientRect();
      selectionState = {
        start_offset: startOffset,
        end_offset: endOffset,
        quote_text: quote,
        anchor_top: Math.max(0, rangeRect.top - stageRect.top),
      };
      if (selectedQuote) selectedQuote.value = quote;
      selectionCard.classList.remove("hidden");
      activeNoteId = 0;
      renderViewer();
    };

    const renderViewerText = () => {
      panel.innerHTML = "";
      const sortedNotes = notes
        .slice()
        .sort((a, b) => Number(a.start_offset || 0) - Number(b.start_offset || 0));
      let cursor = 0;

      markdownDoc.blocks.forEach((block, blockIndex) => {
        const blockEl = document.createElement("span");
        blockEl.className = block.className || "viewer-md-block viewer-md-paragraph";
        if (block.marker) {
          blockEl.setAttribute("data-marker", block.marker);
        }
        (Array.isArray(block.segments) ? block.segments : []).forEach((segment) => {
          appendViewerSegmentWithHighlights(
            blockEl,
            segment,
            cursor,
            sortedNotes,
            activeNoteId,
            (noteId) => {
              activeNoteId = noteId;
              renderViewer();
            }
          );
          cursor += String(segment.text || "").length;
        });
        panel.appendChild(blockEl);
        if (blockIndex < markdownDoc.blocks.length - 1) {
          panel.appendChild(document.createTextNode("\n"));
          cursor += 1;
        }
      });
    };

    const renderNotes = () => {
      notesLayer.innerHTML = "";
      if (noteCount) noteCount.textContent = String(notes.length);

      notes.forEach((note) => {
        const card = document.createElement("article");
        card.className = "viewer-note-card";
        card.setAttribute("data-note-id", String(note.id || 0));
        if (Number(note.id || 0) === activeNoteId) {
          card.classList.add("is-active");
        }

        const quote = document.createElement("blockquote");
        quote.className = "viewer-note-quote";
        quote.textContent = String(note.quote_text || "");
        card.appendChild(quote);

        const body = document.createElement("p");
        body.textContent = String(note.note_text || "");
        card.appendChild(body);

        const meta = document.createElement("div");
        meta.className = "meta";
        const projectLookup = projectLookupFromHeader();
        const tags = Array.isArray(note.tag_labels) ? note.tag_labels : [];
        tags.forEach((tag) => {
          const chip = document.createElement("span");
          chip.className = "badge-note-tag";
          chip.textContent = `#${tag}`;
          meta.appendChild(chip);
        });
        const projects = Array.isArray(note.project_ids) ? note.project_ids : [];
        projects.forEach((projectId) => {
          const chip = document.createElement("span");
          chip.className = "badge-collection";
          chip.textContent = projectLookup.get(Number(projectId || 0)) || `Project ${projectId}`;
          meta.appendChild(chip);
        });
        if (meta.childNodes.length) {
          card.appendChild(meta);
        }

        const actions = document.createElement("div");
        actions.className = "actions";

        const focusBtn = document.createElement("button");
        focusBtn.type = "button";
        focusBtn.className = "btn btn-secondary";
        focusBtn.textContent = "Focus";
        focusBtn.addEventListener("click", () => {
          activeNoteId = Number(note.id || 0);
          renderViewer();
          const mark = panel.querySelector(`[data-note-id="${note.id}"]`);
          if (mark) mark.scrollIntoView({ block: "center", behavior: "smooth" });
        });
        actions.appendChild(focusBtn);

        const deleteBtn = document.createElement("button");
        deleteBtn.type = "button";
        deleteBtn.className = "btn btn-danger";
        deleteBtn.textContent = "Delete";
        deleteBtn.addEventListener("click", async () => {
          if (!confirm("Delete this note?")) return;
          try {
            await postJson("/api/notes.php", { action: "delete", note_id: Number(note.id || 0) });
            notes = notes.filter((item) => Number(item.id || 0) !== Number(note.id || 0));
            if (activeNoteId === Number(note.id || 0)) activeNoteId = 0;
            renderViewer();
            setStatus("Note deleted.");
          } catch (error) {
            setStatus(error.message || "Could not delete note.", true);
          }
        });
        actions.appendChild(deleteBtn);

        card.appendChild(actions);
        notesLayer.appendChild(card);
      });
    };

    const positionCards = () => {
      const cards = [];
      notes.forEach((note) => {
        const noteId = Number(note.id || 0);
        const mark = panel.querySelector(`[data-note-id="${noteId}"]`);
        const card = notesLayer.querySelector(`[data-note-id="${noteId}"]`);
        if (!mark || !card) return;
        cards.push({
          el: card,
          targetTop: Math.max(0, mark.offsetTop - 8),
        });
      });

      if (!selectionCard.classList.contains("hidden") && selectionState) {
        cards.push({
          el: selectionCard,
          targetTop: Math.max(0, Number(selectionState.anchor_top || 0)),
        });
      }

      cards.sort((a, b) => a.targetTop - b.targetTop);
      let nextTop = 0;
      let maxBottom = 0;
      cards.forEach((item) => {
        const top = Math.max(item.targetTop, nextTop);
        item.el.style.top = `${top}px`;
        const height = item.el.offsetHeight;
        nextTop = top + height + 12;
        maxBottom = Math.max(maxBottom, top + height);
      });

      rail.style.minHeight = `${Math.max(panel.offsetHeight, maxBottom)}px`;
    };

    const queueAnnotationLayout = () => {
      if (renderQueued) return;
      renderQueued = true;
      window.requestAnimationFrame(() => {
        renderQueued = false;
        positionCards();
      });
    };

    function renderViewer() {
      renderViewerText();
      renderNotes();
      queueAnnotationLayout();
    }

    panel.addEventListener("mouseup", updateSelectionState);
    panel.addEventListener("keyup", updateSelectionState);

    if (fontFamilySelect) {
      fontFamilySelect.addEventListener("change", () => {
        setReaderFontFamily(fontFamilySelect.value || "helvetica");
        applyReaderTypography();
      });
    }

    if (fontSizeSelect) {
      fontSizeSelect.addEventListener("change", () => {
        setReaderFontSize(fontSizeSelect.value || "12");
        applyReaderTypography();
      });
    }

    if (clearBtn) {
      clearBtn.addEventListener("click", clearDraft);
    }

    if (saveBtn) {
      saveBtn.addEventListener("click", async () => {
        if (!selectionState) {
          setStatus("Select a passage before saving a note.", true);
          return;
        }
        const noteText = String(noteInput?.value || "").trim();
        if (!noteText) {
          setStatus("Write a note before saving.", true);
          return;
        }
        const tagLabels = String(tagsInput?.value || "")
          .split(",")
          .map((value) => value.trim())
          .filter(Boolean);

        try {
          const data = await postJson("/api/notes.php", {
            action: "create",
            note: {
              source_id: Number(source?.id || 0),
              start_offset: selectionState.start_offset,
              end_offset: selectionState.end_offset,
              quote_text: selectionState.quote_text,
              note_text: noteText,
              tag_labels: tagLabels,
              project_ids: getCurrentProjectIds(),
            },
          });
          if (data?.note) {
            notes.push(data.note);
            activeNoteId = Number(data.note.id || 0);
            notes.sort((a, b) => Number(a.start_offset || 0) - Number(b.start_offset || 0));
          }
          clearDraft();
          activeNoteId = Number(data?.note?.id || 0);
          renderViewer();
          setStatus("Note saved.");
        } catch (error) {
          setStatus(error.message || "Could not save note.", true);
        }
      });
    }

    window.addEventListener("resize", queueAnnotationLayout);
    applyReaderTypography();
    renderViewer();
  }

  function wireSettingsPanels() {
    const keyForm = qs("#openai-key-form");
    if (keyForm) {
      keyForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const status = qs("#settings-status");
        try {
          const key = String(new FormData(keyForm).get("openai_api_key") || "");
          const data = await postJson("/api/settings.php", { openai_api_key: key });
          if (status) {
            status.classList.remove("error");
            status.textContent = data.message || "Saved.";
          }
          window.setTimeout(() => location.reload(), 300);
        } catch (error) {
          if (status) {
            status.classList.add("error");
            status.textContent = error.message || "Failed.";
          }
        }
      });
    }

    const assistantSave = qs("#assistant-settings-save");
    if (assistantSave) {
      assistantSave.addEventListener("click", async () => {
        const status = qs("#settings-status");
        try {
          const payload = {
            assistant_enabled: qs("#assistant-enabled")?.value || "1",
            assistant_model: qs("#assistant-model")?.value || "gpt-4o-mini",
          };
          await postJson("/api/settings.php", payload);
          if (status) status.textContent = "Assistant settings saved.";
        } catch (error) {
          if (status) {
            status.classList.add("error");
            status.textContent = error.message || "Failed.";
          }
        }
      });
    }

    const zoteroSave = qs("#zotero-settings-save");
    if (zoteroSave) {
      zoteroSave.addEventListener("click", async () => {
        const status = qs("#settings-status");
        try {
          const payload = {
            zotero_user_id: qs("#zotero-user-id")?.value || "",
            zotero_library_type: qs("#zotero-library-type")?.value || "users",
            zotero_library_id: qs("#zotero-library-id")?.value || "",
            zotero_auto_collection_enabled: qs("#zotero-auto-collection-enabled")?.value || "1",
            zotero_auto_collection_name: qs("#zotero-auto-collection-name")?.value || "Ex Libris",
          };
          const zoteroApiKey = String(qs("#zotero-api-key")?.value || "").trim();
          if (zoteroApiKey) {
            payload.zotero_api_key = zoteroApiKey;
          }
          await postJson("/api/settings.php", payload);
          if (status) status.textContent = "Zotero settings saved.";
        } catch (error) {
          if (status) {
            status.classList.add("error");
            status.textContent = error.message || "Failed.";
          }
        }
      });
    }

    const zoteroPreview = qs("#zotero-preview-btn");
    if (zoteroPreview) {
      zoteroPreview.addEventListener("click", async () => {
        try {
          const data = await postJson("/api/zotero.php", { mode: "preview", limit: 10 });
          setText("#zotero-preview-output", JSON.stringify(data.items || [], null, 2));
        } catch (error) {
          setText("#zotero-preview-output", error.message || "Preview failed.");
        }
      });
    }
  }

  async function runAssistant(action, payload, outSelector) {
    const data = await postJson("/api/assistant.php", { action, ...payload });
    setText(outSelector, JSON.stringify(data, null, 2));
  }

  function showAssistantError(outSelector, message) {
    const out = elFromOutSelector(outSelector);
    if (!out) return;
    out.innerHTML = "";
    const p = document.createElement("p");
    p.className = "muted";
    p.textContent = message || "Request failed.";
    out.appendChild(p);
  }

  function wireAssistantPanels() {
    const semanticBtn = qs("#semantic-search-btn");
    if (semanticBtn) {
      semanticBtn.addEventListener("click", async () => {
        try {
          const data = await postJson("/api/assistant.php", { action: "semantic_search", query: qs("#semantic-query")?.value || "", limit: 12 });
          renderSemanticResults(data?.results || []);
        } catch (error) {
          setText("#semantic-results", error.message || "Search failed.");
        }
      });
    }

    const clusterBtn = qs("#cluster-btn");
    if (clusterBtn) clusterBtn.addEventListener("click", () => runAssistant("cluster_themes", {}, "#assistant-panel-output").catch((e) => setText("#assistant-panel-output", e.message)));
    const digestBtn = qs("#digest-btn");
    if (digestBtn) digestBtn.addEventListener("click", () => runAssistant("weekly_digest", {}, "#assistant-panel-output").catch((e) => setText("#assistant-panel-output", e.message)));
    const zoteroSyncBtn = qs("#zotero-sync-btn");
    if (zoteroSyncBtn) zoteroSyncBtn.addEventListener("click", async () => {
      try {
        const data = await postJson("/api/zotero.php", { mode: "import", limit: 25 });
        setText("#assistant-panel-output", JSON.stringify(data, null, 2));
        location.reload();
      } catch (error) {
        setText("#assistant-panel-output", error.message || "Sync failed.");
      }
    });
    const zoteroCollectionsBtn = qs("#zotero-sync-collections-btn");
    if (zoteroCollectionsBtn) zoteroCollectionsBtn.addEventListener("click", async () => {
      try {
        const data = await postJson("/api/zotero.php", { mode: "collections" });
        setText("#assistant-panel-output", JSON.stringify(data, null, 2));
      } catch (error) {
        setText("#assistant-panel-output", error.message || "Collection sync failed.");
      }
    });
    const zoteroPushUnsyncedBtn = qs("#zotero-push-unsynced-btn");
    if (zoteroPushUnsyncedBtn) zoteroPushUnsyncedBtn.addEventListener("click", async () => {
      try {
        const data = await postJson("/api/zotero.php", { mode: "push_unsynced" });
        setText("#assistant-panel-output", JSON.stringify(data, null, 2));
        location.reload();
      } catch (error) {
        setText("#assistant-panel-output", error.message || "Push unsynced failed.");
      }
    });

    const annotateBtn = qs("#assistant-annotate-btn");
    if (annotateBtn)
      annotateBtn.addEventListener("click", () =>
        runSourceAssistant("annotate_source", { source_id: Number(annotateBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) =>
          showAssistantError("#assistant-source-output", e.message)
        )
      );
    const qualityBtn = qs("#assistant-quality-btn");
    if (qualityBtn)
      qualityBtn.addEventListener("click", () =>
        runSourceAssistant("source_quality", { source_id: Number(qualityBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) =>
          showAssistantError("#assistant-source-output", e.message)
        )
      );
    const citationQaBtn = qs("#assistant-citation-qa-btn");
    if (citationQaBtn)
      citationQaBtn.addEventListener("click", () =>
        runSourceAssistant("citation_qa", { source_id: Number(citationQaBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) =>
          showAssistantError("#assistant-source-output", e.message)
        )
      );
    const similarBtn = qs("#assistant-similar-btn");
    if (similarBtn)
      similarBtn.addEventListener("click", () =>
        runSourceAssistant("similar_sources", { source_id: Number(similarBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) =>
          showAssistantError("#assistant-source-output", e.message)
        )
      );

    const claimBtn = qs("#claim-link-btn");
    if (claimBtn) claimBtn.addEventListener("click", () => runAssistant("link_claims", { draft: qs("#claim-draft")?.value || "" }, "#claim-link-results").catch((e) => setText("#claim-link-results", e.message)));
    const zoteroPushSourceBtn = qs("#zotero-push-source-btn");
    if (zoteroPushSourceBtn)
      zoteroPushSourceBtn.addEventListener("click", async () => {
        try {
          const sourceId = Number(zoteroPushSourceBtn.dataset.sourceId || 0);
          if (!sourceId) return;
          const data = await postJson("/api/zotero.php", { mode: "push_one", source_id: sourceId });
          const out = qs("#assistant-source-output");
          if (out) {
            out.innerHTML = "";
            const pre = document.createElement("pre");
            pre.className = "muted";
            pre.textContent = JSON.stringify(data, null, 2);
            out.appendChild(pre);
          }
        } catch (error) {
          showAssistantError("#assistant-source-output", error.message || "Push failed.");
        }
      });
  }

  function wireReaderPanel() {
    const runBtn = qs("#reader-run-btn");
    if (!runBtn) return;

    const clearBtn = qs("#reader-clear-btn");
    const contextInput = qs("#reader-context");
    const sourceQueryInput = qs("#reader-source-query");
    const suggestionsWrap = qs("#reader-source-suggestions");
    const selectedWrap = qs("#reader-selected-sources");
    const historyWrap = qs("#reader-history-list");
    const historyRefreshBtn = qs("#reader-history-refresh-btn");
    const loaderPre = qs("#reader-loader");
    const resultsPanel = qs("#reader-results-panel");
    const includedSummary = qs("#reader-included-summary");
    const resultsPre = qs("#reader-results");
    const tracePanel = qs("#reader-trace-panel");
    const tracePre = qs("#reader-trace");
    const externalPanel = qs("#reader-external-panel");
    const externalList = qs("#reader-external-list");
    const selectedSources = new Map();
    const historyRuns = new Map();
    let sourceQueryTimer = null;
    let loaderTimer = null;
    let loaderFrame = 0;

    const startLoader = () => {
      if (!loaderPre) return;
      loaderPre.classList.remove("hidden");
      loaderFrame = 0;
      loaderPre.textContent = AI_RUNNER_FRAMES[0];
      if (loaderTimer) window.clearInterval(loaderTimer);
      loaderTimer = window.setInterval(() => {
        loaderFrame = (loaderFrame + 1) % AI_RUNNER_FRAMES.length;
        loaderPre.textContent = AI_RUNNER_FRAMES[loaderFrame];
      }, 120);
    };

    const stopLoader = () => {
      if (!loaderPre) return;
      if (loaderTimer) {
        window.clearInterval(loaderTimer);
        loaderTimer = null;
      }
      loaderPre.classList.add("hidden");
      loaderPre.textContent = "";
    };

    const buildSourceLookup = (sourcesList) => {
      const lookup = new Map();
      (Array.isArray(sourcesList) ? sourcesList : []).forEach((source) => {
        const id = Number(source?.id || 0);
        if (!id) return;
        lookup.set(id, {
          id,
          title: String(source?.title || ""),
          authors: Array.isArray(source?.authors) ? source.authors.map((value) => String(value || "")) : [],
          url: String(source?.url || ""),
        });
      });
      return lookup;
    };

    const sourceTooltip = (lookup, id) => {
      const source = lookup.get(Number(id || 0));
      if (!source) return `Source #${id}`;
      const title = source.title || `Source #${id}`;
      const authorText = source.authors.slice(0, 3).join(", ");
      const base = authorText ? `${authorText} — ${title}` : title;
      return source.url ? `${base}\n${source.url}` : base;
    };

    const synthesisSourceIds = (synthesis) => {
      const out = new Set();
      const claims = Array.isArray(synthesis?.claims) ? synthesis.claims : [];
      claims.forEach((claim) => {
        const ids = Array.isArray(claim?.source_ids) ? claim.source_ids : [];
        ids.forEach((id) => {
          const num = Number(id || 0);
          if (num > 0) out.add(num);
        });
      });
      const connections = Array.isArray(synthesis?.connections) ? synthesis.connections : [];
      connections.forEach((item) => {
        const ids = Array.isArray(item?.source_ids) ? item.source_ids : [];
        ids.forEach((id) => {
          const num = Number(id || 0);
          if (num > 0) out.add(num);
        });
      });
      return Array.from(out);
    };

    const hydrateLookupForSynthesis = async (synthesis, baseLookup) => {
      const lookup = new Map(baseLookup instanceof Map ? baseLookup : []);
      const missingIds = synthesisSourceIds(synthesis).filter((id) => !lookup.has(id));
      if (!missingIds.length) return lookup;
      try {
        const data = await postJson("/api/assistant.php", {
          action: "reader_source_lookup",
          source_ids: missingIds,
        });
        const enriched = buildSourceLookup(data?.sources || []);
        enriched.forEach((value, key) => {
          lookup.set(key, value);
        });
      } catch (_) {}
      return lookup;
    };

    const appendSourceRefs = (parent, ids, lookup) => {
      const safeIds = Array.isArray(ids) ? ids.map((id) => Number(id || 0)).filter((id) => id > 0) : [];
      if (!safeIds.length) {
        parent.appendChild(document.createTextNode("none"));
        return;
      }
      safeIds.forEach((id, index) => {
        const link = document.createElement("a");
        link.href = `/source.php?id=${id}`;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.className = "reader-source-ref";
        link.title = sourceTooltip(lookup, id);
        link.textContent = String(id);
        parent.appendChild(link);
        if (index < safeIds.length - 1) {
          parent.appendChild(document.createTextNode(", "));
        }
      });
    };

    const renderSynthesis = (synthesis, sourceLookup) => {
      if (!resultsPanel || !resultsPre) return;
      if (!synthesis || typeof synthesis !== "object") {
        resultsPanel.classList.add("hidden");
        resultsPre.textContent = "";
        if (includedSummary) includedSummary.innerHTML = "";
        return;
      }
      const lookup = sourceLookup instanceof Map ? sourceLookup : new Map();
      resultsPre.innerHTML = "";

      const pushLine = (text) => {
        const line = document.createElement("div");
        line.textContent = text;
        resultsPre.appendChild(line);
      };

      pushLine(`Verdict: ${(synthesis.verdict || "skim").toUpperCase()}`);
      if (synthesis.verdict_reason) pushLine(`Reason: ${synthesis.verdict_reason}`);
      if (synthesis.why_now) pushLine(`Why now: ${synthesis.why_now}`);

      const co = String(synthesis.companion_overview || "").trim();
      const cd = String(synthesis.companion_deeper_context || "").trim();
      const ct = String(synthesis.companion_reading_tips || "").trim();
      if (co || cd || ct) {
        pushLine("");
        pushLine("Reading companion:");
        if (co) pushLine(`  Overview: ${co}`);
        if (cd) pushLine(`  Context & background: ${cd}`);
        if (ct) pushLine(`  How to read: ${ct}`);
      }

      if (Array.isArray(synthesis.claims) && synthesis.claims.length) {
        pushLine("");
        pushLine("Claims:");
        synthesis.claims.forEach((claim, idx) => {
          pushLine(`  ${idx + 1}. ${claim?.text || ""}`);
          const meta = document.createElement("div");
          meta.appendChild(document.createTextNode(`     strength: ${claim?.strength || "unknown"} | source_ids: `));
          appendSourceRefs(meta, claim?.source_ids, lookup);
          resultsPre.appendChild(meta);
        });
      }

      if (Array.isArray(synthesis.connections) && synthesis.connections.length) {
        pushLine("");
        pushLine("Connections:");
        synthesis.connections.forEach((connection, idx) => {
          const row = document.createElement("div");
          row.appendChild(document.createTextNode(`  ${idx + 1}. [`));
          appendSourceRefs(row, connection?.source_ids, lookup);
          row.appendChild(document.createTextNode(`] ${connection?.relation || ""} — ${connection?.note || ""}`));
          resultsPre.appendChild(row);
        });
      }

      if (Array.isArray(synthesis.open_questions) && synthesis.open_questions.length) {
        pushLine("");
        pushLine("Open questions:");
        synthesis.open_questions.forEach((item) => pushLine(`  - ${item}`));
      }
      if (Array.isArray(synthesis.cautions) && synthesis.cautions.length) {
        pushLine("");
        pushLine("Cautions:");
        synthesis.cautions.forEach((item) => pushLine(`  - ${item}`));
      }

      resultsPanel.classList.remove("hidden");
    };

    const renderTrace = (trace, tokenUsage) => {
      if (!tracePanel || !tracePre) return;
      const lines = formatTraceLines(trace);
      if (tokenUsage && typeof tokenUsage === "object" && Object.keys(tokenUsage).length) {
        lines.push("", "Token usage:");
        lines.push(JSON.stringify(tokenUsage, null, 2));
      }
      if (!lines.length) {
        tracePanel.classList.add("hidden");
        tracePre.textContent = "";
        return;
      }
      tracePre.textContent = lines.join("\n");
      tracePanel.classList.remove("hidden");
    };

    const selectedSourceIds = () =>
      Array.from(selectedSources.keys())
        .map((id) => Number(id || 0))
        .filter((id) => id > 0);

    const sourceLabel = (source) => {
      const title = source?.title || "Untitled source";
      const authorText = Array.isArray(source?.authors) ? source.authors.slice(0, 2).join(", ") : "";
      const yearText = source?.year ? ` (${source.year})` : "";
      return `${title}${authorText ? ` — ${authorText}` : ""}${yearText}`;
    };

    const renderIncludedSourceSummary = (primarySources, expandedSources) => {
      if (!includedSummary) return;
      includedSummary.innerHTML = "";

      const sections = [
        { title: "Explicitly Included", sources: Array.isArray(primarySources) ? primarySources : [], empty: "None explicitly selected." },
      ];
      if (Array.isArray(expandedSources) && expandedSources.length) {
        sections.push({ title: "Also Added For Context", sources: expandedSources, empty: "No extra sources were added." });
      }

      sections.forEach((section) => {
        const wrap = document.createElement("article");
        wrap.className = "reader-run-source-group";

        const heading = document.createElement("h3");
        heading.textContent = section.title;
        wrap.appendChild(heading);

        if (!section.sources.length) {
          const empty = document.createElement("p");
          empty.className = "muted";
          empty.textContent = section.empty;
          wrap.appendChild(empty);
          includedSummary.appendChild(wrap);
          return;
        }

        const list = document.createElement("div");
        list.className = "stack";
        section.sources.forEach((source) => {
          const row = document.createElement("div");
          row.className = "reader-run-source-item";
          const sourceId = Number(source?.id || 0);
          if (sourceId > 0) {
            const link = document.createElement("a");
            link.href = `/source.php?id=${sourceId}`;
            link.className = "reader-source-ref";
            link.textContent = sourceLabel(source);
            row.appendChild(link);
          } else {
            row.textContent = sourceLabel(source);
          }
          list.appendChild(row);
        });
        wrap.appendChild(list);
        includedSummary.appendChild(wrap);
      });
    };

    const renderSelectedSources = () => {
      if (!selectedWrap) return;
      selectedWrap.innerHTML = "";
      const ids = selectedSourceIds();
      if (!ids.length) {
        const empty = document.createElement("p");
        empty.className = "muted";
        empty.textContent = "No sources selected yet. Add some from semantic search, or run with context only.";
        selectedWrap.appendChild(empty);
        return;
      }
      ids.forEach((id) => {
        const source = selectedSources.get(id) || { id, title: `Source #${id}`, authors: [], year: "" };
        const row = document.createElement("div");
        row.className = "reader-source-tag";
        const text = document.createElement("span");
        text.textContent = sourceLabel(source);
        row.appendChild(text);

        const removeBtn = document.createElement("button");
        removeBtn.type = "button";
        removeBtn.className = "reader-source-tag-remove";
        removeBtn.textContent = "x";
        removeBtn.setAttribute("aria-label", `Remove ${source.title || `source ${id}`}`);
        removeBtn.addEventListener("click", () => {
          selectedSources.delete(id);
          renderSelectedSources();
        });
        row.appendChild(removeBtn);
        selectedWrap.appendChild(row);
      });
    };

    const addSource = (source) => {
      const id = Number(source?.id || 0);
      if (!id) return;
      selectedSources.set(id, {
        id,
        title: source?.title || "",
        authors: Array.isArray(source?.authors) ? source.authors : [],
        year: source?.year || "",
      });
      renderSelectedSources();
    };

    const renderSuggestions = (results) => {
      if (!suggestionsWrap) return;
      suggestionsWrap.innerHTML = "";
      if (!Array.isArray(results) || !results.length) {
        suggestionsWrap.classList.add("hidden");
        return;
      }
      results.forEach((result) => {
        const source = result?.source || {};
        const id = Number(source.id || 0);
        if (!id) return;
        const row = document.createElement("div");
        row.className = "row";

        const text = document.createElement("button");
        text.type = "button";
        text.className = "reader-suggestion-title";
        const score = Number(result?.score || 0).toFixed(3);
        text.textContent = `${sourceLabel(source)} [${score}]`;
        text.addEventListener("click", () => {
          addSource(source);
          renderSuggestions(results);
        });
        row.appendChild(text);

        const addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.className = "btn btn-load";
        addBtn.textContent = selectedSources.has(id) ? "Added" : "Add";
        addBtn.disabled = selectedSources.has(id);
        addBtn.addEventListener("click", () => {
          addSource(source);
          renderSuggestions(results);
        });
        row.appendChild(addBtn);
        suggestionsWrap.appendChild(row);
      });
      suggestionsWrap.classList.remove("hidden");
    };

    const runSemanticAutocomplete = async (query) => {
      if (!query) {
        renderSuggestions([]);
        return;
      }
      try {
        const data = await postJson("/api/assistant.php", {
          action: "semantic_search",
          query,
          limit: 8,
        });
        renderSuggestions(data?.results || []);
      } catch (error) {
        setStatus(error.message || "Semantic source search failed.", true);
      }
    };

    const addExternalCandidate = async (candidate, button) => {
      if (!candidate?.url) return;
      try {
        button.textContent = "Adding...";
        const processed = await postJson("/api/process.php", { input: candidate.url });
        const source = processed?.source || {};
        const saved = await postJson("/api/save.php", { source });
        button.textContent = saved?.id ? "Added" : "Saved";
      } catch (error) {
        button.textContent = "Add failed";
        setStatus(error.message || "Could not add external candidate.", true);
      } finally {
        setTimeout(() => {
          if (button.textContent !== "Added" && button.textContent !== "Saved") {
            button.textContent = "Add";
          }
        }, 1500);
      }
    };

    const renderExternalCandidates = (candidates) => {
      if (!externalPanel || !externalList) return;
      externalList.innerHTML = "";
      if (!Array.isArray(candidates) || !candidates.length) {
        externalPanel.classList.add("hidden");
        return;
      }
      candidates.forEach((candidate) => {
        const card = document.createElement("div");
        card.className = "card";

        const title = document.createElement("div");
        const authorText = Array.isArray(candidate.authors) ? candidate.authors.slice(0, 3).join(", ") : "";
        const year = candidate.year ? ` (${candidate.year})` : "";
        title.textContent = `${candidate.title || "Untitled"}${year}`;
        card.appendChild(title);

        const meta = document.createElement("p");
        meta.className = "muted";
        meta.textContent = [authorText, candidate.provider, candidate.why_relevant].filter(Boolean).join(" — ");
        card.appendChild(meta);

        const actions = document.createElement("div");
        actions.className = "actions";
        if (candidate.url) {
          const visit = document.createElement("a");
          visit.className = "btn btn-secondary";
          visit.href = candidate.url;
          visit.target = "_blank";
          visit.rel = "noopener noreferrer";
          visit.textContent = "Visit";
          actions.appendChild(visit);
        }
        const addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.className = "btn btn-load";
        addBtn.textContent = "Add";
        addBtn.addEventListener("click", () => addExternalCandidate(candidate, addBtn));
        actions.appendChild(addBtn);
        card.appendChild(actions);

        externalList.appendChild(card);
      });
      externalPanel.classList.remove("hidden");
    };

    const loadRun = async (runId) => {
      const run = historyRuns.get(Number(runId || 0));
      if (!run) return;
      const output = run.output_json || {};
      const runSources = Array.isArray(output.sources) ? output.sources : [];
      const lookup = await hydrateLookupForSynthesis(output.synthesis || {}, buildSourceLookup(runSources));
      renderSynthesis(output.synthesis || {}, lookup);
      renderIncludedSourceSummary(output.primary_sources || [], output.expanded_sources || []);
      renderTrace(output.trace || [], output.token_usage || {});
      renderExternalCandidates(output?.synthesis?.external_candidates || []);
      if (contextInput) contextInput.value = String(run.input_text || "");

      const runSourceIds = Array.isArray(output.selected_source_ids) ? output.selected_source_ids : (Array.isArray(output.source_ids) ? output.source_ids : []);
      selectedSources.clear();
      runSourceIds.forEach((id) => {
        const source = runSources.find((item) => Number(item?.id || 0) === Number(id)) || { id, title: `Source #${id}`, authors: [], year: "" };
        addSource(source);
      });
      renderSelectedSources();
      setStatus(`Loaded Reader run #${run.id}.`);
    };

    const renderHistory = (runs) => {
      if (!historyWrap) return;
      historyWrap.innerHTML = "";
      historyRuns.clear();
      if (!Array.isArray(runs) || !runs.length) {
        const empty = document.createElement("p");
        empty.className = "muted";
        empty.textContent = "No saved Reader runs yet.";
        historyWrap.appendChild(empty);
        return;
      }
      runs.forEach((run) => {
        historyRuns.set(Number(run.id || 0), run);
        const card = document.createElement("article");
        card.className = "card stack";

        const heading = document.createElement("div");
        const verdict = String(run?.summary?.verdict || "skim").toUpperCase();
        heading.textContent = `#${run.id} · ${verdict} · ${run.created_at || ""}`;
        card.appendChild(heading);

        const detail = document.createElement("p");
        detail.className = "muted";
        detail.textContent = run?.summary?.preview || run.input_text || "Reader run";
        card.appendChild(detail);

        const actions = document.createElement("div");
        actions.className = "actions";
        const loadBtn = document.createElement("button");
        loadBtn.type = "button";
        loadBtn.className = "btn btn-secondary";
        loadBtn.textContent = "Load";
        loadBtn.addEventListener("click", () => {
          loadRun(run.id);
        });
        actions.appendChild(loadBtn);
        card.appendChild(actions);

        historyWrap.appendChild(card);
      });
    };

    const loadHistory = async () => {
      await withBusyButton(historyRefreshBtn, "Refreshing...", async () => {
        try {
          const data = await postJson("/api/assistant.php", {
            action: "reader_history",
            limit: 20,
          });
          renderHistory(data.runs || []);
        } catch (error) {
          setStatus(error.message || "Could not load Reader history.", true);
        }
      });
    };

    const runReader = async () => {
      const sourceIds = selectedSourceIds();
      const context = String(contextInput?.value || "").trim();
      if (!sourceIds.length && !context) {
        setStatus("Select at least one source or enter research context.", true);
        stopLoader();
        return;
      }
      try {
        runBtn.textContent = "Running...";
        setStatus("Building reader synthesis...");
        const data = await postJson("/api/assistant.php", {
          action: "reader_synthesis",
          source_ids: sourceIds,
          context,
          expand_k: 3,
        });
        const lookup = await hydrateLookupForSynthesis(data.synthesis || {}, buildSourceLookup(data.sources || []));
        renderSynthesis(data.synthesis || {}, lookup);
        renderIncludedSourceSummary(data.primary_sources || [], data.expanded_sources || []);
        renderTrace(data.trace || [], data.token_usage || {});
        renderExternalCandidates(data.synthesis?.external_candidates || []);
        setStatus(`Reader complete (${Number(data.source_count || sourceIds.length)} source(s)).`);
        await loadHistory();
      } catch (error) {
        setStatus(error.message || "Reader failed.", true);
      } finally {
        stopLoader();
        runBtn.textContent = "Run Reader";
      }
    };

    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        if (contextInput) contextInput.value = "";
        if (sourceQueryInput) sourceQueryInput.value = "";
        selectedSources.clear();
        renderSelectedSources();
        renderSuggestions([]);
        if (resultsPanel) resultsPanel.classList.add("hidden");
        if (tracePanel) tracePanel.classList.add("hidden");
        if (externalPanel) externalPanel.classList.add("hidden");
        if (resultsPre) resultsPre.textContent = "";
        if (includedSummary) includedSummary.innerHTML = "";
        if (tracePre) tracePre.textContent = "";
        if (externalList) externalList.innerHTML = "";
        stopLoader();
        setStatus("Reader form cleared.");
      });
    }

    if (sourceQueryInput) {
      sourceQueryInput.addEventListener("input", () => {
        const query = String(sourceQueryInput.value || "").trim();
        if (sourceQueryTimer) window.clearTimeout(sourceQueryTimer);
        sourceQueryTimer = window.setTimeout(() => {
          runSemanticAutocomplete(query);
        }, 240);
      });
    }

    if (historyRefreshBtn) {
      historyRefreshBtn.addEventListener("click", loadHistory);
    }

    const initialJson = qs("#reader-initial-selected");
    if (initialJson) {
      try {
        const parsed = JSON.parse(initialJson.textContent || "[]");
        if (Array.isArray(parsed)) {
          parsed.forEach((source) => addSource(source));
        }
      } catch (_) {}
    }

    renderSelectedSources();
    loadHistory();
    runBtn.addEventListener("click", () => {
      startLoader();
      runReader();
    });
  }

  function wireCollectionTagAutocomplete() {
    qsa('input[name="project_names"][list]').forEach((input) => {
      const listId = input.getAttribute("list");
      if (!listId) return;
      const dataList = document.getElementById(listId);
      if (!dataList) return;
      const options = Array.from(dataList.querySelectorAll("option"))
        .map((option) => String(option.value || "").trim())
        .filter(Boolean);
      if (!options.length) return;

      input.addEventListener("keydown", (event) => {
        if (event.key !== "Tab" && event.key !== "Enter") return;
        const value = String(input.value || "");
        const parts = value.split(",");
        const activePart = (parts[parts.length - 1] || "").trim().toLowerCase();
        if (!activePart) return;
        const match = options.find((name) => name.toLowerCase().startsWith(activePart));
        if (!match) return;
        parts[parts.length - 1] = ` ${match}`;
        input.value = `${parts.join(",").trimStart()}, `;
        event.preventDefault();
      });
    });
  }

  function wireDedupeCleanup() {
    const scanBtn = qs("#dedupe-scan-btn");
    const groupsWrap = qs("#dedupe-groups");
    const status = qs("#dedupe-status");
    const useAi = qs("#dedupe-use-ai");
    if (!scanBtn || !groupsWrap) return;

    const sourceLine = (source) => {
      const parts = [
        `#${source.id || 0}`,
        source.title || "(Untitled)",
        source.year ? `(${source.year})` : "",
        source.doi ? `DOI ${source.doi}` : "",
        source.zotero_item_key ? `Zotero ${source.zotero_item_key}` : "",
      ].filter(Boolean);
      return parts.join(" | ");
    };

    const renderGroups = (groups) => {
      groupsWrap.innerHTML = "";
      if (!Array.isArray(groups) || !groups.length) {
        const empty = document.createElement("article");
        empty.className = "card";
        empty.textContent = "No obvious duplicate groups found.";
        groupsWrap.appendChild(empty);
        return;
      }

      groups.forEach((group, index) => {
        const ids = Array.isArray(group.ids) ? group.ids.map((id) => Number(id || 0)).filter(Boolean) : [];
        if (ids.length < 2) return;
        const suggestedKeepId = Number(group.suggested_keep_id || 0);

        const card = document.createElement("article");
        card.className = "card stack";
        const title = document.createElement("h2");
        title.textContent = `Group ${index + 1} (${ids.length} records)`;
        card.appendChild(title);

        const reason = document.createElement("p");
        reason.className = "muted";
        reason.textContent = group.suggestion_reason || "Suggested keep record selected by heuristic.";
        card.appendChild(reason);

        const list = document.createElement("div");
        list.className = "stack";
        const sources = Array.isArray(group.sources) ? group.sources : [];
        sources.forEach((source) => {
          const row = document.createElement("label");
          const radio = document.createElement("input");
          radio.type = "radio";
          radio.name = `dedupe-keep-${index}`;
          radio.value = String(source.id || 0);
          if (Number(source.id || 0) === suggestedKeepId) radio.checked = true;
          const text = document.createElement("span");
          text.textContent = ` Keep ${sourceLine(source)}`;
          row.appendChild(radio);
          row.appendChild(text);
          list.appendChild(row);
        });
        card.appendChild(list);

        const actions = document.createElement("div");
        actions.className = "actions";
        const applyBtn = document.createElement("button");
        applyBtn.type = "button";
        applyBtn.className = "btn btn-danger";
        applyBtn.textContent = "Apply Delete Duplicates";
        applyBtn.addEventListener("click", async () => {
          const selected = card.querySelector(`input[name="dedupe-keep-${index}"]:checked`);
          const keepId = Number(selected?.value || 0);
          if (!keepId) return;
          const deleteIds = ids.filter((id) => id !== keepId);
          if (!deleteIds.length) return;
          if (!confirm(`Keep #${keepId} and delete ${deleteIds.length} duplicates?`)) return;
          try {
            applyBtn.textContent = "Cleaning...";
            await postJson("/api/assistant.php", { action: "dedupe_apply", keep_id: keepId, delete_ids: deleteIds });
            card.remove();
            if (status) status.textContent = `Applied group cleanup: kept #${keepId}, deleted ${deleteIds.length}.`;
          } catch (error) {
            if (status) status.textContent = error.message || "Cleanup failed.";
          } finally {
            applyBtn.textContent = "Apply Delete Duplicates";
          }
        });
        actions.appendChild(applyBtn);
        card.appendChild(actions);
        groupsWrap.appendChild(card);
      });
    };

    scanBtn.addEventListener("click", async () => {
      try {
        scanBtn.textContent = "Scanning...";
        if (status) status.textContent = "Scanning for duplicate groups...";
        const data = await postJson("/api/assistant.php", {
          action: "dedupe_scan",
          use_ai: useAi && !useAi.checked ? "0" : "1",
        });
        renderGroups(data.groups || []);
        if (status) status.textContent = `Found ${Number(data.group_count || 0)} duplicate groups (${Number(data.pair_count || 0)} matching pairs).`;
      } catch (error) {
        if (status) status.textContent = error.message || "Duplicate scan failed.";
      } finally {
        scanBtn.textContent = "Scan Duplicates";
      }
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    const processBtn = qs("#process-input");
    if (processBtn) processBtn.addEventListener("click", processDumpInput);
    const sourceForm = qs("#source-form");
    if (sourceForm) sourceForm.addEventListener("submit", saveSource);

    wireDropInput();
    wireSearchAndCards();
    wireCollectionTagAutocomplete();
    wireDedupeCleanup();
    wireThemeAndFormat();
    wireCurrentProjects();
    wirePdfActions();
    wireAnnotationViewer();
    wireSettingsPanels();
    wireAssistantPanels();
    wireReaderPanel();
  });
})();
