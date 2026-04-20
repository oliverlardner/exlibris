(function () {
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

  function adminToken() {
    return localStorage.getItem("exlibris_admin_token") || "";
  }

  function headersWithAuth() {
    const headers = { "Content-Type": "application/json" };
    const token = adminToken();
    if (token) headers["X-Admin-Token"] = token;
    return headers;
  }

  async function postJson(url, payload) {
    const response = await fetch(endpoint(url), {
      method: "POST",
      headers: headersWithAuth(),
      body: JSON.stringify(payload),
    });
    let data = null;
    try {
      data = await response.json();
    } catch (_) {}
    if (!response.ok) {
      throw new Error(data?.error || `Request failed (${response.status})`);
    }
    return data || {};
  }

  function setText(selector, value) {
    const el = qs(selector);
    if (el) el.textContent = value || "";
  }

  function setStatus(value, isError) {
    const el = qs("#app-status");
    if (!el) return;
    el.textContent = value || "";
    el.classList.toggle("error", Boolean(isError));
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
      const base = button.textContent;
      button.addEventListener("click", async () => {
        try {
          await copyText(button.getAttribute("data-copy-citation") || "");
          button.textContent = "Copied";
        } catch (_) {
          button.textContent = "Copy failed";
        } finally {
          setTimeout(() => (button.textContent = base), 1200);
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

  function wireAssistantPanels() {
    const semanticBtn = qs("#semantic-search-btn");
    if (semanticBtn) {
      semanticBtn.addEventListener("click", async () => {
        try {
          await runAssistant("semantic_search", { query: qs("#semantic-query")?.value || "", limit: 12 }, "#semantic-results");
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
    if (annotateBtn) annotateBtn.addEventListener("click", () => runAssistant("annotate_source", { source_id: Number(annotateBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) => setText("#assistant-source-output", e.message)));
    const qualityBtn = qs("#assistant-quality-btn");
    if (qualityBtn) qualityBtn.addEventListener("click", () => runAssistant("source_quality", { source_id: Number(qualityBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) => setText("#assistant-source-output", e.message)));
    const citationQaBtn = qs("#assistant-citation-qa-btn");
    if (citationQaBtn) citationQaBtn.addEventListener("click", () => runAssistant("citation_qa", { source_id: Number(citationQaBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) => setText("#assistant-source-output", e.message)));
    const similarBtn = qs("#assistant-similar-btn");
    if (similarBtn) similarBtn.addEventListener("click", () => runAssistant("similar_sources", { source_id: Number(similarBtn.dataset.sourceId || 0) }, "#assistant-source-output").catch((e) => setText("#assistant-source-output", e.message)));

    const claimBtn = qs("#claim-link-btn");
    if (claimBtn) claimBtn.addEventListener("click", () => runAssistant("link_claims", { draft: qs("#claim-draft")?.value || "" }, "#claim-link-results").catch((e) => setText("#claim-link-results", e.message)));
    const zoteroPushSourceBtn = qs("#zotero-push-source-btn");
    if (zoteroPushSourceBtn) zoteroPushSourceBtn.addEventListener("click", async () => {
      try {
        const sourceId = Number(zoteroPushSourceBtn.dataset.sourceId || 0);
        if (!sourceId) return;
        const data = await postJson("/api/zotero.php", { mode: "push_one", source_id: sourceId });
        setText("#assistant-source-output", JSON.stringify(data, null, 2));
      } catch (error) {
        setText("#assistant-source-output", error.message || "Push failed.");
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

    const loaderFrames = [
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

    const startLoader = () => {
      if (!loaderPre) return;
      loaderPre.classList.remove("hidden");
      loaderFrame = 0;
      loaderPre.textContent = loaderFrames[0];
      if (loaderTimer) window.clearInterval(loaderTimer);
      loaderTimer = window.setInterval(() => {
        loaderFrame = (loaderFrame + 1) % loaderFrames.length;
        loaderPre.textContent = loaderFrames[loaderFrame];
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
      renderTrace(output.trace || [], output.token_usage || {});
      renderExternalCandidates(output?.synthesis?.external_candidates || []);
      if (contextInput) contextInput.value = String(run.input_text || "");

      const runSourceIds = Array.isArray(output.source_ids) ? output.source_ids : [];
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
    wireSettingsPanels();
    wireAssistantPanels();
    wireReaderPanel();
  });
})();
