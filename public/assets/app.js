(function () {
  const state = {
    csrfToken: window.TMKCTL.csrfToken,
    questions: window.TMKCTL.questions || [],
    questionsError: window.TMKCTL.questionsError || "",
    students: window.TMKCTL.students || [],
    stack: window.TMKCTL.stack || [],
    activeStudentId: window.TMKCTL.activeStudentId || null,
    cursorStudentId: window.TMKCTL.activeStudentId || null,
    currentExamLabel: window.TMKCTL.currentExamLabel || "",
    workspaceLabel: window.TMKCTL.workspaceLabel || "",
    examDisplayLabel: window.TMKCTL.examDisplayLabel || window.TMKCTL.currentExamLabel || window.TMKCTL.workspaceLabel || "",
    mode: "follow",
    manualQuestionId: null,
    currentNoteKey: "",
    noteDirty: false,
    noteLockVersion: 0,
    noteConflict: false,
    isImportStudents: [],
    accordions: {
      examining: true,
      preparing: true,
      done: false
    },
    accordionCounts: {
      examining: -1,
      preparing: -1,
      done: -1
    }
  };

  const $ = (id) => document.getElementById(id);
  const questionById = (id) => state.questions.find((q) => q.id === id) || null;
  const studentById = (id) => state.students.find((s) => Number(s.id) === Number(id)) || null;
  const stackByStudentId = (id) => state.stack.find((item) => Number(item.student_id) === Number(id)) || null;
  const studyBadge = (studyType) => {
    if (studyType === "single") return "1OBOR";
    if (studyType === "double") return "2OBOR";
    return "?";
  };
  const studyLabel = (studyType) => {
    if (studyType === "single") return "jednoobor";
    if (studyType === "double") return "dvouobor";
    return "neznámé";
  };

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function showMessage(message, type) {
    setGlobalStatus(message, type);
  }

  function setGlobalStatus(message, type) {
    const box = $("global-status-message");
    if (!box) return;
    box.textContent = message || "";
    box.classList.toggle("error", type === "error");
    box.classList.toggle("success", type !== "error" && Boolean(message));
    if (message) {
      window.clearTimeout(setGlobalStatus.timer);
      setGlobalStatus.timer = window.setTimeout(() => {
        if (box.textContent === message) {
          box.textContent = "";
          box.classList.remove("error", "success");
        }
      }, 6000);
    }
  }

  async function api(url, options) {
    const response = await fetch(url, options);
    const contentType = response.headers.get("content-type") || "";
    const payload = contentType.includes("application/json") ? await response.json() : { ok: response.ok };
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || payload.message || "Požadavek selhal.");
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function postForm(url, data) {
    data.append("csrf_token", state.csrfToken);
    return api(url, { method: "POST", body: data });
  }

  function selectedIsTermId() {
    const checked = document.querySelector("input[name='is_term_id']:checked");
    return checked ? checked.value : "";
  }

  function openModal(name) {
    const layer = $("modal-layer");
    if (!layer) return;
    layer.classList.remove("hidden");
    document.querySelectorAll(".modal-window").forEach((modal) => modal.classList.add("hidden"));
    const modal = $(`modal-${name}`);
    if (!modal) return;
    modal.classList.remove("hidden");
    if (name === "help") {
      loadHelpContent();
    }
    const focusTarget = modal.querySelector("input, textarea, select, button, a[href]");
    if (focusTarget) focusTarget.focus();
  }

  function closeModal() {
    const layer = $("modal-layer");
    if (layer) layer.classList.add("hidden");
    document.querySelectorAll(".modal-window").forEach((modal) => modal.classList.add("hidden"));
  }

  function stackItemByStudentId(studentId) {
    return stackByStudentId(studentId) || {
      student_id: studentId,
      state: "waiting",
      question_id: ""
    };
  }

  function currentStudentId() {
    return state.cursorStudentId || state.activeStudentId || null;
  }

  function currentStackItem() {
    return stackByStudentId(currentStudentId());
  }

  async function postIsImport(action, extra) {
    const data = new FormData();
    data.append("action", action);
    data.append("raw_text", $("is-import-text").value);
    Object.entries(extra || {}).forEach(([key, value]) => {
      data.append(key, value);
    });
    return postForm("api/students_is_import.php", data);
  }

  function renderIsTerms(terms) {
    const box = $("is-term-list");
    const previewButton = $("is-preview-students");
    const importSelected = $("is-import-selected");
    const importAll = $("is-import-all");
    state.isImportStudents = [];
    $("is-preview").innerHTML = "";
    importSelected.disabled = true;
    importAll.disabled = true;

    if (!terms.length) {
      box.innerHTML = '<div class="manual-warning">Nenalezen žádný termín Teorie masové komunikace / TMK.</div>';
      previewButton.disabled = true;
      return;
    }

    box.innerHTML = terms.map((term, index) => `
      <label class="is-term-option">
        <input type="radio" name="is_term_id" value="${escapeHtml(term.term_id)}"${index === 0 ? " checked" : ""}>
        <span>${escapeHtml(term.date_raw)} — ${escapeHtml(term.title)}${term.student_count_declared !== null ? ` — ${escapeHtml(term.student_count_declared)} přihlášených` : ""}</span>
      </label>
    `).join("");
    previewButton.disabled = false;
  }

  function renderIsPreview(students, term) {
    const box = $("is-preview");
    state.isImportStudents = students || [];
    $("is-import-selected").disabled = !state.isImportStudents.some((student) => student.can_import);
    $("is-import-all").disabled = !state.isImportStudents.some((student) => student.can_import);

    if (!state.isImportStudents.length) {
      box.innerHTML = '<div class="manual-warning">Ve vybraném termínu nebyly nalezeny žádné řádky studujících.</div>';
      return;
    }

    box.innerHTML = `
      <div class="is-preview-title">${escapeHtml(term.date_raw)} — ${escapeHtml(term.title)}</div>
      <div class="is-preview-scroll">
        <table class="is-preview-table">
          <thead>
            <tr>
              <th>import</th>
              <th>čas</th>
              <th>jméno</th>
              <th>UČO</th>
              <th>kód</th>
              <th>typ</th>
              <th>sem</th>
              <th>poznámka</th>
              <th>stav</th>
            </tr>
          </thead>
          <tbody>
            ${state.isImportStudents.map((student) => `
              <tr>
                <td><input type="checkbox" class="is-import-check" value="${escapeHtml(student.row_index)}"${student.can_import ? " checked" : " disabled"}></td>
                <td>${escapeHtml(student.time_range)}</td>
                <td>${escapeHtml(student.name)}</td>
                <td>${escapeHtml(student.uco || "")}</td>
                <td>${escapeHtml(student.study_code || "")}</td>
                <td><b class="study-badge">${escapeHtml(studyBadge(student.study_type))}</b>${escapeHtml(studyLabel(student.study_type))}</td>
                <td>${escapeHtml(student.semester || "")}</td>
                <td title="${escapeHtml(student.import_note || "")}">${escapeHtml(student.extra || student.import_note || "")}</td>
                <td>${escapeHtml(student.status_label || "")}</td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  function selectedIsRows() {
    return Array.from(document.querySelectorAll(".is-import-check:checked")).map((input) => Number(input.value));
  }

  function questionLabel(questionId) {
    const question = questionById(questionId);
    return question ? (question.short_title || question.title) : "bez otázky";
  }

  function renderQueueRow(student, item) {
    const isCurrent = Number(student.id) === Number(currentStudentId());
    const marker = item.state === "examining" ? "[ZK] " : (item.state === "preparing" ? "[P] " : (item.state === "done" ? "[OK] " : ""));
    const row = document.createElement("div");
    row.className = "student-row" + (isCurrent ? " current" : "") + (item.state ? ` state-${item.state}` : "");
    row.innerHTML = `
      <button type="button" class="student-main">
        <strong><span class="cursor-marker">${isCurrent ? ">" : ""}</span>${marker}${escapeHtml(student.name)}</strong>
        <span><b class="study-badge">${escapeHtml(studyBadge(student.study_type))}</b>${escapeHtml(student.uco || "bez UČO")} · ${escapeHtml(questionLabel(item.question_id))}</span>
      </button>
      <div class="student-actions button-row tight">
        ${moveButtons(item)}
        <button type="button" data-action="active">AKTIVNÍ</button>
      </div>
      ${renderInlineQuestionAssign(item)}
    `;
    row.querySelector(".student-main").addEventListener("click", () => setActiveStudent(student.id));
    row.querySelectorAll("[data-move]").forEach((button) => {
      button.addEventListener("click", () => moveStudentItem(student.id, button.dataset.move));
    });
    row.querySelector("[data-action='active']").addEventListener("click", () => setActiveStudent(student.id));
    const inlineAssign = row.querySelector("[data-action='assign-inline']");
    if (inlineAssign) {
      inlineAssign.addEventListener("click", () => {
        const select = row.querySelector(".inline-question-select");
        assignQuestion(item.id, select ? select.value : "");
      });
    }
    return row;
  }

  function renderInlineQuestionAssign(item) {
    if (!["preparing", "examining"].includes(item.state) || !item.id) return "";
    const hasQuestion = Boolean(item.question_id);
    return `
      <div class="inline-question-edit">
        <span>${escapeHtml(questionLabel(item.question_id))}</span>
        <select class="inline-question-select" aria-label="Otázka pro studujícího">
          <option value="">bez otázky</option>
          ${state.questions.map((q) => `<option value="${escapeHtml(q.id)}"${q.id === item.question_id ? " selected" : ""}>${escapeHtml(q.id)} · ${escapeHtml(q.short_title || q.title)}</option>`).join("")}
        </select>
        <button type="button" data-action="assign-inline">${hasQuestion ? "UPRAVIT OTÁZKU" : "PŘIŘADIT"}</button>
      </div>
    `;
  }

  function renderStudents() {
    const box = $("students-list");
    box.innerHTML = "";
    const waiting = state.students.filter((student) => stackItemByStudentId(student.id).state === "waiting");
    if (!waiting.length) {
      box.innerHTML = '<div class="empty-row">Fronta je prázdná.</div>';
      return;
    }
    waiting.forEach((student) => box.appendChild(renderQueueRow(student, stackItemByStudentId(student.id))));
  }

  function renderStack() {
    renderStatusSection("examining");
    renderStatusSection("preparing");
    renderStatusSection("done");
  }

  function renderStatusSection(stackState) {
    const items = state.stack.filter((item) => item.state === stackState);
    const count = $(`${stackState}-count`);
    const body = $(`${stackState}-list`);
    const toggle = $(`toggle-${stackState}`);
    if (!body || !toggle) return;
    if (count) count.textContent = `(${items.length})`;
    const previousCount = state.accordionCounts[stackState] ?? -1;
    if (!items.length && (stackState === "examining" || stackState === "preparing")) {
      state.accordions[stackState] = false;
    } else if (items.length && previousCount === 0 && (stackState === "examining" || stackState === "preparing")) {
      state.accordions[stackState] = true;
    }
    state.accordionCounts[stackState] = items.length;
    toggle.setAttribute("aria-expanded", state.accordions[stackState] ? "true" : "false");
    body.classList.toggle("hidden", !state.accordions[stackState]);
    body.innerHTML = "";
    if (!items.length) {
      body.innerHTML = '<div class="empty-row">nikdo</div>';
      return;
    }
    items.forEach((item) => {
      const student = studentById(item.student_id);
      if (student) body.appendChild(renderQueueRow(student, item));
    });
  }

  function moveButtons(item) {
    const allowed = {
      waiting: [["preparing", "POTÍTKO"], ["examining", "ZKOUŠET"], ["done", "HOTOVO"]],
      preparing: [["waiting", "ZPĚT"], ["examining", "ZKOUŠET"], ["done", "HOTOVO"]],
      examining: [["preparing", "ZPĚT"], ["done", "HOTOVO"]],
      done: [["examining", "ZKOUŠET"]]
    };
    return (allowed[item.state] || []).map(([next, label]) => `<button type="button" data-move="${next}">${label}</button>`).join("");
  }

  function selectedQuestionId() {
    if (state.mode === "manual") {
      return state.manualQuestionId || (state.questions[0] && state.questions[0].id) || null;
    }
    const stackItem = stackByStudentId(currentStudentId());
    return stackItem ? stackItem.question_id : null;
  }

  function renderQuestionSelect() {
    const select = $("manual-question-select");
    select.innerHTML = state.questions.map((q) => `<option value="${escapeHtml(q.id)}">${escapeHtml(q.short_title || q.title)}</option>`).join("");
    if (!state.manualQuestionId && state.questions[0]) {
      state.manualQuestionId = state.questions[0].id;
    }
    select.value = state.manualQuestionId || "";
  }

  function renderQuestion() {
    const question = questionById(selectedQuestionId());
    const panel = $("question-panel");
    $("manual-warning").classList.toggle("hidden", state.mode !== "manual");
    $("question-mode-follow").classList.toggle("selected", state.mode === "follow");
    $("question-mode-manual").classList.toggle("selected", state.mode === "manual");
    $("question-mode-follow").setAttribute("aria-pressed", state.mode === "follow" ? "true" : "false");
    $("question-mode-manual").setAttribute("aria-pressed", state.mode === "manual" ? "true" : "false");
    $("manual-question-select").disabled = state.mode !== "manual";

    if (state.questionsError) {
      panel.innerHTML = `<div class="mode-line">${state.mode === "manual" ? "ZOBRAZENÍ: RUČNÍ VÝBĚR" : "ZOBRAZENÍ: AKTIVNÍ STUDUJÍCÍ"}</div><p class="empty">${escapeHtml(state.questionsError)}</p>`;
      loadNote();
      return;
    }

    if (!question) {
      const questionId = selectedQuestionId();
      panel.innerHTML = `<div class="mode-line">${state.mode === "manual" ? "ZOBRAZENÍ: RUČNÍ VÝBĚR" : "ZOBRAZENÍ: AKTIVNÍ STUDUJÍCÍ"}</div><p class="empty">${questionId ? "Přiřazená otázka nebyla nalezena v JSON." : "Aktivní studující nemá přiřazenou otázku."}</p>`;
      loadNote();
      return;
    }

    panel.innerHTML = `
      <div class="mode-line">${state.mode === "manual" ? "ZOBRAZENÍ: RUČNÍ VÝBĚR" : "ZOBRAZENÍ: AKTIVNÍ STUDUJÍCÍ"}</div>
      <h1>${escapeHtml(question.title)}</h1>
      <div class="short-title">${escapeHtml(question.short_title || "")}</div>
      ${(!question.source_refs || question.source_refs.length === 0) ? '<div class="source-warning">Bez ověřených zdrojů — placeholder.</div>' : ""}
      ${renderList("Osnova", question.outline)}
      ${renderTerms(question.key_terms)}
      ${renderAuthors(question.authors)}
      ${renderList("Zaměření zkoušejícího", question.examiner_focus)}
      ${renderList("Doplňující otázky", question.followup_questions)}
      ${renderList("Časté chyby", question.common_mistakes)}
    `;
    loadNote();
  }

  function renderList(title, items) {
    if (!items || !items.length) return "";
    return `<section><h2>${escapeHtml(title)}</h2><ul>${items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul></section>`;
  }

  function renderTerms(items) {
    if (!items || !items.length) return "";
    return `<section><h2>Klíčové pojmy</h2>${items.map((item) => `
      <div class="term">
        <strong>${escapeHtml(item.term)}</strong>
        <p>${escapeHtml(item.definition)}</p>
        ${(item.authors && item.authors.length) ? `<small>${escapeHtml(item.authors.join(", "))}</small>` : ""}
      </div>
    `).join("")}</section>`;
  }

  function renderAuthors(items) {
    if (!items || !items.length) return "";
    return `<section><h2>Autoři</h2><ul>${items.map((item) => `<li><strong>${escapeHtml(item.name)}</strong>: ${escapeHtml(item.role)}</li>`).join("")}</ul></section>`;
  }

  function renderNotesContext() {
    const student = studentById(currentStudentId());
    const question = questionById(selectedQuestionId());
    const modeLabel = $("note-mode-label");
    $("note-context").textContent = student && question
      ? `${student.name} · ${student.uco || "bez UČO"} · ${question.short_title || question.title}`
      : (!student ? "Vyber studujícího pro psaní poznámek." : `${student.name} · ${student.uco || "bez UČO"} · obecná poznámka`);
    if (modeLabel) {
      modeLabel.textContent = !student
        ? "ŽÁDNÝ STUDUJÍCÍ"
        : (question ? "POZNÁMKA K OTÁZCE" : "OBECNÁ POZNÁMKA KE STUDUJÍCÍMU");
    }
    const globalActive = $("global-active-student");
    if (globalActive) {
      globalActive.textContent = student ? `KURZOR: ${student.name}` : "";
    }
    renderExamLabel();
  }

  function renderExamLabel() {
    const label = $("global-exam-label");
    if (label) {
      label.textContent = state.examDisplayLabel ? `TERMÍN: ${state.examDisplayLabel}` : "";
    }
    const workspaceLabel = $("global-workspace-label");
    if (workspaceLabel) {
      workspaceLabel.textContent = state.workspaceLabel || state.examDisplayLabel || "tmkctl";
      workspaceLabel.title = workspaceLabel.textContent;
    }
  }

  async function loadNote() {
    renderNotesContext();
    const studentId = currentStudentId();
    const questionId = selectedQuestionId();
    const key = `${studentId || ""}:${questionId || ""}`;
    if (state.currentNoteKey === key && state.noteDirty) {
      return;
    }
    state.currentNoteKey = key;
    if (!studentId) {
      $("note-text").value = "";
      $("suggested-grade").value = "";
      $("note-text").disabled = true;
      $("suggested-grade").disabled = true;
      state.noteLockVersion = 0;
      state.noteConflict = false;
      return;
    }
    $("note-text").disabled = false;
    $("suggested-grade").disabled = false;
    try {
      const payload = await api(`api/notes.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId || "")}`);
      if (state.currentNoteKey !== key) return;
      applyLoadedNote(payload.note);
      state.noteDirty = false;
      state.noteConflict = false;
      setStatus("");
    } catch (error) {
      setStatus(error.message);
    }
  }

  function applyLoadedNote(note) {
    $("note-text").value = note.note_text || "";
    $("suggested-grade").value = note.suggested_grade || "";
    state.noteLockVersion = Number(note.lock_version || 0);
  }

  async function refreshCurrentNote() {
    const studentId = currentStudentId();
    if (!studentId || state.noteConflict) return;
    const questionId = selectedQuestionId();
    const key = `${studentId || ""}:${questionId || ""}`;
    try {
      const payload = await api(`api/notes.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId || "")}`);
      if (state.currentNoteKey !== key) return;
      const remoteVersion = Number(payload.note.lock_version || 0);
      if (remoteVersion === state.noteLockVersion) return;
      if (state.noteDirty) {
        state.noteConflict = true;
        setStatus("Poznámku mezitím upravil jiný uživatel. Uložení je pozastavené, aby se změny nepřepsaly.");
        return;
      }
      applyLoadedNote(payload.note);
      setStatus("Poznámka aktualizována z jiné obrazovky.");
    } catch (error) {
      setStatus(error.message);
    }
  }

  async function saveNote(manual) {
    const studentId = currentStudentId();
    const questionId = selectedQuestionId();
    if (!studentById(studentId)) {
      setStatus("Nelze uložit: chybí studující.");
      return;
    }
    if (state.noteConflict && !manual) {
      return;
    }
    const data = new FormData();
    data.append("student_id", studentId);
    data.append("question_id", questionId || "");
    data.append("note_text", $("note-text").value);
    data.append("suggested_grade", $("suggested-grade").value);
    data.append("base_lock_version", String(state.noteLockVersion || 0));
    try {
      const payload = await postForm("api/notes.php", data);
      applyLoadedNote(payload.note);
      state.noteDirty = false;
      state.noteConflict = false;
      setStatus((payload.message || "Poznámka byla uložena.") + (manual ? "" : " (autosave)"));
    } catch (error) {
      if (error.payload && error.payload.conflict) {
        state.noteConflict = true;
      }
      setStatus(error.message);
    }
  }

  function setStatus(message) {
    $("save-status").textContent = message;
    if (message) setGlobalStatus(message);
  }

  async function refreshStudents() {
    const payload = await api("api/students.php");
    state.students = payload.students;
    renderStudents();
  }

  async function refreshStack() {
    const payload = await api("api/stack.php");
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
    if (!state.cursorStudentId && state.activeStudentId) {
      state.cursorStudentId = state.activeStudentId;
    }
    renderAll();
  }

  async function setActiveStudent(studentId) {
    const data = new FormData();
    data.append("action", "active");
    data.append("student_id", studentId);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.activeStudentId = payload.activeStudentId;
    state.cursorStudentId = payload.activeStudentId;
    state.stack = payload.stack;
    renderAll();
  }

  async function addToStack(studentId) {
    const data = new FormData();
    data.append("action", "add");
    data.append("student_id", studentId);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
    state.cursorStudentId = studentId;
    renderAll();
  }

  async function moveStudentItem(studentId, nextState) {
    let item = stackByStudentId(studentId);
    if (!item || !item.id) {
      await addToStack(studentId);
      item = stackByStudentId(studentId);
    }
    if (!item || !item.id) {
      showMessage("Studujícího se nepodařilo přidat do fronty.", "error");
      return;
    }
    await moveStack(item.id, nextState);
  }

  async function moveStack(stackId, nextState) {
    const data = new FormData();
    data.append("action", "move");
    data.append("stack_id", stackId);
    data.append("state", nextState);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
    if (["preparing", "examining"].includes(nextState)) {
      state.accordions[nextState] = true;
    }
    renderAll();
  }

  async function assignQuestion(stackId, questionId) {
    const data = new FormData();
    data.append("action", "assign");
    data.append("stack_id", stackId);
    data.append("question_id", questionId);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
    renderAll();
  }

  function renderAll() {
    renderStudents();
    renderStack();
    renderQuestion();
    renderNotesContext();
    renderExamLabel();
  }

  function download(format) {
    const studentId = currentStudentId();
    const questionId = selectedQuestionId();
    if (!studentById(studentId)) {
      setStatus("Nelze exportovat: chybí studující.");
      return;
    }
    window.location.href = `api/export_note.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId || "")}&format=${format}`;
  }

  function setOperationsStatus(message) {
    const status = $("operations-status");
    if (status) {
      status.textContent = message || "";
    }
    if (message) setGlobalStatus(message);
  }

  function setExportStatus(message) {
    const status = $("export-status");
    if (status) status.textContent = message || "";
    if (message) setGlobalStatus(message);
  }

  function setQuestionPackStatusMessage(message, type) {
    const status = $("question-pack-result");
    if (status) status.textContent = message || "";
    if (message) setGlobalStatus(message, type);
  }

  function renderQuestionPackStatus(status) {
    const box = $("question-pack-status");
    if (!box || !status) return;
    const errors = status.errors || [];
    const warnings = status.warnings || [];
    const backups = status.backups || [];
    box.innerHTML = `
      <div class="question-pack-grid">
        <div>PATH</div><div>${escapeHtml(status.path || "data/questions.reviewed.json")}</div>
        <div>OTÁZEK</div><div>${escapeHtml(status.question_count)}</div>
        <div>REVIEWED</div><div>${escapeHtml(status.reviewed_count)}</div>
        <div>GENERATED</div><div>${escapeHtml(status.generated_count)}</div>
        <div>NEEDS_REVIEW</div><div>${escapeHtml(status.needs_review_count)}</div>
        <div>BEZ SOURCE_REFS</div><div>${escapeHtml(status.without_source_refs_count)}</div>
        <div>POSLEDNÍ ÚPRAVA</div><div>${escapeHtml(status.last_modified || "neznámé")}</div>
        <div>JSON</div><div>${status.valid_json ? "OK" : "CHYBA"}</div>
        <div>SCHÉMA</div><div>${status.schema_valid ? "OK" : "CHYBA"}</div>
      </div>
      <div class="split-title">CHYBY</div>
      ${errors.length ? `<ul class="validation-list error">${errors.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
      <div class="split-title">VAROVÁNÍ</div>
      ${warnings.length ? `<ul class="validation-list">${warnings.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
      <div class="split-title">POSLEDNÍ ZÁLOHY</div>
      ${backups.length ? `<ul class="backup-list">${backups.map((backup) => `<li>${escapeHtml(backup.name)} · ${escapeHtml(backup.modified)} · ${escapeHtml(backup.size)} B</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
    `;
  }

  function renderMergePreview(summary, validation) {
    const box = $("question-merge-preview");
    if (!box) return;
    if (!summary) {
      box.innerHTML = "";
      return;
    }
    const conflicts = summary.duplicate_id_conflicts || [];
    const warnings = summary.validation_warnings || (validation && validation.warnings) || [];
    const errors = summary.validation_errors || (validation && validation.errors) || [];
    box.innerHTML = `
      <div class="question-pack-grid">
        <div>AKTUÁLNÍ OTÁZKY</div><div>${escapeHtml(summary.current_question_count)}</div>
        <div>PŘIDANÉ</div><div>${escapeHtml(summary.added_question_count)}</div>
        <div>NAHRAZENÉ</div><div>${escapeHtml(summary.replaced_question_count)}</div>
        <div>STRATEGIE</div><div>${summary.strategy === "replace-existing" ? "nahradit existující" : "ponechat existující"}</div>
        <div>KONFLIKTY ID</div><div>${escapeHtml(conflicts.length)}</div>
      </div>
      <div class="split-title">DUPLICITNÍ ID</div>
      ${conflicts.length ? `<ul class="validation-list">${conflicts.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
      <div class="split-title">VAROVÁNÍ MERGE</div>
      ${warnings.length ? `<ul class="validation-list">${warnings.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
      <div class="split-title">CHYBY MERGE</div>
      ${errors.length ? `<ul class="validation-list error">${errors.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : '<div class="empty-row">žádné</div>'}
    `;
  }

  async function loadQuestionPackStatus() {
    const payload = await api("api/questions_status.php");
    renderQuestionPackStatus(payload.status);
    setQuestionPackStatusMessage("Validace otázkového balíku dokončena.", payload.status && payload.status.schema_valid ? "success" : "error");
    return payload.status;
  }

  async function loadHelpContent() {
    const box = $("help-content");
    if (!box || box.dataset.loaded === "1") return;
    const source = box.dataset.helpSrc || "assets/help.html";
    try {
      const response = await fetch(source, { cache: "no-cache" });
      if (!response.ok) throw new Error("help fetch failed");
      box.innerHTML = await response.text();
      box.dataset.loaded = "1";
    } catch (error) {
      box.textContent = "Nápovědu se nepodařilo načíst.";
    }
  }

  function toggleAiChat(open) {
    const win = $("ai-chat-window");
    const button = $("ai-chat-toggle");
    if (!win || !button) return;
    const nextOpen = typeof open === "boolean" ? open : win.classList.contains("hidden");
    win.classList.toggle("hidden", !nextOpen);
    button.setAttribute("aria-expanded", nextOpen ? "true" : "false");
    if (nextOpen) {
      const closeButton = $("ai-chat-close");
      if (closeButton) closeButton.focus();
    } else {
      button.focus();
    }
  }

  function focusRelative(offset) {
    const visible = state.students.filter((student) => stackItemByStudentId(student.id).state === "waiting");
    const candidates = visible.length ? visible : state.students;
    if (!candidates.length) {
      writeConsole("není koho vybrat");
      return;
    }
    const current = currentStudentId();
    let index = candidates.findIndex((student) => Number(student.id) === Number(current));
    if (index < 0) index = 0;
    index = Math.max(0, Math.min(candidates.length - 1, index + offset));
    setActiveStudent(candidates[index].id).catch((error) => writeConsole(error.message));
  }

  function writeConsole(message) {
    const log = $("console-log");
    if (!log) return;
    const line = document.createElement("div");
    line.textContent = message;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
  }

  function runConsoleCommand(raw) {
    const command = raw.trim().replace(/^:/, "");
    if (!command) return;
    writeConsole(":" + command);
    if (command === "help") {
      writeConsole("příkazy: :help :add :import :reset :export :questions :logout :focus next :focus prev :active :question active :question manual");
    } else if (command === "add") {
      openModal("add");
    } else if (command === "import") {
      openModal("import");
    } else if (command === "reset") {
      openModal("reset");
    } else if (command === "export") {
      openModal("export");
    } else if (command === "questions") {
      openModal("questions");
      loadQuestionPackStatus().catch((error) => setQuestionPackStatusMessage(error.message, "error"));
    } else if (command === "logout") {
      window.location.href = "logout.php";
    } else if (command === "focus next") {
      focusRelative(1);
    } else if (command === "focus prev") {
      focusRelative(-1);
    } else if (command === "active") {
      const studentId = currentStudentId();
      if (studentId) setActiveStudent(studentId).catch((error) => writeConsole(error.message));
      else writeConsole("není vybraný studující");
    } else if (command === "question active") {
      state.mode = "follow";
      renderQuestion();
    } else if (command === "question manual") {
      state.mode = "manual";
      renderQuestion();
    } else {
      writeConsole("zatím nepodporováno");
    }
  }

  function initEvents() {
    $("student-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        const formData = new FormData(event.target);
        const noteText = String(formData.get("note_text") || "").trim();
        const payload = await postForm("api/students.php", formData);
        state.students = payload.students;
        state.stack = payload.stack || state.stack;
        if (Object.prototype.hasOwnProperty.call(payload, "activeStudentId")) {
          state.activeStudentId = payload.activeStudentId;
        }
        if (payload.result && payload.result.id) {
          state.cursorStudentId = payload.result.id;
          if (noteText) {
            const noteData = new FormData();
            noteData.append("student_id", payload.result.id);
            noteData.append("question_id", "");
            noteData.append("note_text", noteText);
            noteData.append("suggested_grade", "");
            await postForm("api/notes.php", noteData);
          }
        }
        event.target.reset();
        showMessage(payload.message);
        const addStatus = $("add-status");
        if (addStatus) addStatus.textContent = "";
        renderAll();
      } catch (error) {
        showMessage(error.message, "error");
        const addStatus = $("add-status");
        if (addStatus) addStatus.textContent = error.message;
      }
    });

    $("import-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        const payload = await postForm("api/students_import.php", new FormData(event.target));
        state.students = payload.students;
        state.stack = payload.stack || state.stack;
        if (Object.prototype.hasOwnProperty.call(payload, "activeStudentId")) {
          state.activeStudentId = payload.activeStudentId;
        }
        event.target.reset();
        const extra = payload.result && payload.result.errors && payload.result.errors.length ? " " + payload.result.errors.join(" ") : "";
        showMessage((payload.message || `Importováno: ${payload.imported}`) + extra, extra ? "error" : "success");
        renderAll();
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    const resetExamForm = $("reset-exam-form");
    if (resetExamForm) {
      resetExamForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const data = new FormData(event.target);
        if ((data.get("confirmation") || "") !== "RESET") {
          setOperationsStatus("Pro reset napiš přesně RESET.");
          return;
        }
        try {
          const payload = await postForm("api/reset_exam.php", data);
          state.students = [];
          state.stack = [];
          state.activeStudentId = null;
          state.cursorStudentId = null;
          state.currentExamLabel = payload.currentExamLabel || "";
          state.examDisplayLabel = payload.examDisplayLabel || state.currentExamLabel || state.workspaceLabel || "";
          event.target.reset();
          $("note-text").value = "";
          $("suggested-grade").value = "";
          state.noteDirty = false;
          renderAll();
          setOperationsStatus(payload.message || "Termín byl resetován.");
          showMessage(payload.message || "Termín byl resetován.", "success");
        } catch (error) {
          setOperationsStatus(error.message);
        }
      });
    }

    ["examining", "preparing", "done"].forEach((stackState) => {
      const toggle = $(`toggle-${stackState}`);
      const body = $(`${stackState}-list`);
      if (!toggle || !body) return;
      toggle.addEventListener("click", () => {
        state.accordions[stackState] = !state.accordions[stackState];
        toggle.setAttribute("aria-expanded", state.accordions[stackState] ? "true" : "false");
        body.classList.toggle("hidden", !state.accordions[stackState]);
      });
    });

    const isDetectTerms = $("is-detect-terms");
    if (isDetectTerms) {
      isDetectTerms.addEventListener("click", async () => {
        try {
          const payload = await postIsImport("detect_terms");
          renderIsTerms(payload.terms || []);
          showMessage(payload.message, payload.terms && payload.terms.length ? "success" : "error");
        } catch (error) {
          showMessage(error.message, "error");
        }
      });
    }

    const isPreviewStudents = $("is-preview-students");
    if (isPreviewStudents) {
      isPreviewStudents.addEventListener("click", async () => {
        try {
          const termId = selectedIsTermId();
          const payload = await postIsImport("preview", { term_id: termId });
          renderIsPreview(payload.students || [], payload.term || {});
          const warningText = payload.warnings && payload.warnings.length ? " Varování: " + payload.warnings.join(" ") : "";
          showMessage((payload.message || "Náhled připraven.") + warningText, warningText ? "error" : "success");
        } catch (error) {
          showMessage(error.message, "error");
        }
      });
    }

    async function importIsStudents(rows) {
      const termId = selectedIsTermId();
      const payload = await postIsImport("import", {
        term_id: termId,
        selected_rows: JSON.stringify(rows)
      });
      state.students = payload.students || state.students;
      state.stack = payload.stack || state.stack;
      if (Object.prototype.hasOwnProperty.call(payload, "activeStudentId")) {
        state.activeStudentId = payload.activeStudentId;
      }
      if (!state.cursorStudentId && state.activeStudentId) {
        state.cursorStudentId = state.activeStudentId;
      }
      renderAll();
      const warningText = payload.warnings && payload.warnings.length ? " Varování: " + payload.warnings.join(" ") : "";
      showMessage((payload.message || "Import dokončen.") + warningText, warningText ? "error" : "success");
    }

    const isImportSelected = $("is-import-selected");
    if (isImportSelected) {
      isImportSelected.addEventListener("click", async () => {
        try {
          await importIsStudents(selectedIsRows());
        } catch (error) {
          showMessage(error.message, "error");
        }
      });
    }

    const isImportAll = $("is-import-all");
    if (isImportAll) {
      isImportAll.addEventListener("click", async () => {
        try {
          const rows = state.isImportStudents.filter((student) => student.can_import).map((student) => Number(student.row_index));
          await importIsStudents(rows);
        } catch (error) {
          showMessage(error.message, "error");
        }
      });
    }

    const isImportClear = $("is-import-clear");
    if (isImportClear) {
      isImportClear.addEventListener("click", () => {
        $("is-import-text").value = "";
        $("is-term-list").innerHTML = "";
        $("is-preview").innerHTML = "";
        $("is-preview-students").disabled = true;
        $("is-import-selected").disabled = true;
        $("is-import-all").disabled = true;
        state.isImportStudents = [];
        showMessage("");
      });
    }

    $("question-mode-follow").addEventListener("click", () => {
      state.mode = "follow";
      renderQuestion();
    });
    $("question-mode-manual").addEventListener("click", () => {
      state.mode = "manual";
      renderQuestion();
    });

    $("manual-question-select").addEventListener("change", (event) => {
      state.manualQuestionId = event.target.value;
      renderQuestion();
    });
    $("assign-current-question").addEventListener("click", async () => {
      let item = currentStackItem();
      const questionId = $("manual-question-select").value || selectedQuestionId();
      if (!item) {
        await addToStack(currentStudentId());
        item = currentStackItem();
      }
      if (!item || !questionId) {
        showMessage("Nelze přiřadit: chybí studující ve frontě nebo otázka.", "error");
        return;
      }
      assignQuestion(item.id, questionId);
    });

    $("note-text").addEventListener("input", () => { state.noteDirty = true; });
    $("suggested-grade").addEventListener("input", () => { state.noteDirty = true; });
    $("save-note").addEventListener("click", () => saveNote(true));
    $("copy-note").addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText($("note-text").value);
        setStatus("Zkopírováno");
      } catch (error) {
        setStatus("Clipboard není dostupný.");
      }
    });
    $("download-txt").addEventListener("click", () => download("txt"));
    $("download-md").addEventListener("click", () => download("md"));
    $("global-add").addEventListener("click", () => openModal("add"));
    $("global-import").addEventListener("click", () => openModal("import"));
    $("global-reset").addEventListener("click", () => openModal("reset"));
    $("global-export-all").addEventListener("click", () => openModal("export"));
    $("global-questions").addEventListener("click", () => {
      openModal("questions");
      loadQuestionPackStatus().catch((error) => setQuestionPackStatusMessage(error.message, "error"));
    });
    $("global-help").addEventListener("click", () => openModal("help"));
    $("global-console").addEventListener("click", () => {
      openModal("console");
      writeConsole("zadej :help");
    });
    document.querySelectorAll("[data-close-modal]").forEach((button) => {
      button.addEventListener("click", closeModal);
    });
    $("modal-layer").addEventListener("click", (event) => {
      if (event.target === $("modal-layer")) closeModal();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeModal();
    });
    $("copy-all-notes").addEventListener("click", async () => {
      try {
        const response = await fetch("api/export_all_notes.php?format=md");
        const text = await response.text();
        if (!response.ok) throw new Error(text || "Export selhal.");
        if (navigator.clipboard) {
          await navigator.clipboard.writeText(text);
          $("clipboard-fallback").classList.add("hidden");
          setExportStatus("Všechny poznámky zkopírovány.");
        } else {
          $("clipboard-fallback").classList.remove("hidden");
          $("clipboard-fallback").value = text;
          $("clipboard-fallback").select();
          setExportStatus("Clipboard není dostupný. Označený text zkopíruj ručně.");
        }
      } catch (error) {
        setExportStatus(error.message);
      }
    });
    $("validate-question-pack").addEventListener("click", () => {
      loadQuestionPackStatus().catch((error) => setQuestionPackStatusMessage(error.message, "error"));
    });
    $("question-pack-merge-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const submitter = event.submitter;
      const data = new FormData(event.target);
      data.set("action", submitter && submitter.value === "merge" ? "merge" : "validate");
      try {
        const payload = await postForm("api/questions_merge.php", data);
        renderMergePreview(payload.summary, payload.validation);
        if (payload.status) renderQuestionPackStatus(payload.status);
        if (Array.isArray(payload.questions)) {
          state.questions = payload.questions;
          state.questionsError = "";
          state.manualQuestionId = state.questions[0] ? state.questions[0].id : null;
          renderQuestionSelect();
          renderAll();
        }
        const warningCount = payload.summary && payload.summary.validation_warnings ? payload.summary.validation_warnings.length : 0;
        setQuestionPackStatusMessage((payload.message || "Merge dokončen.") + (warningCount ? ` Varování: ${warningCount}.` : ""), "success");
        if (data.get("action") === "merge") {
          event.target.reset();
          const fileName = $("merge-questions-json-file-name");
          if (fileName) fileName.textContent = "no file selected";
        }
      } catch (error) {
        renderMergePreview(error.payload && error.payload.summary, error.payload && error.payload.validation);
        setQuestionPackStatusMessage(error.message, "error");
      }
    });
    $("question-pack-upload-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        const payload = await postForm("api/questions_upload.php", new FormData(event.target));
        renderQuestionPackStatus(payload.status);
        if (Array.isArray(payload.questions)) {
          state.questions = payload.questions;
          state.questionsError = "";
          state.manualQuestionId = state.questions[0] ? state.questions[0].id : null;
          renderQuestionSelect();
          renderAll();
        }
        event.target.reset();
        const fileName = $("questions-json-file-name");
        if (fileName) fileName.textContent = "no file selected";
        const warningCount = payload.validation && payload.validation.warnings ? payload.validation.warnings.length : 0;
        setQuestionPackStatusMessage((payload.message || "Balík otázek byl nahrán.") + (warningCount ? ` Varování: ${warningCount}.` : ""), "success");
      } catch (error) {
        const validation = error.payload && error.payload.validation;
        if (validation) {
          renderQuestionPackStatus({
            ...(validation.stats || {}),
            errors: validation.errors || [],
            warnings: validation.warnings || [],
            valid_json: validation.valid_json,
            schema_valid: validation.schema_valid,
            path: "data/questions.reviewed.json",
            last_modified: "",
            backups: []
          });
        }
        setQuestionPackStatusMessage(error.message, "error");
      }
    });
    $("console-form").addEventListener("submit", (event) => {
      event.preventDefault();
      const input = $("console-input");
      runConsoleCommand(input.value);
      input.value = "";
    });
    const fileInput = $("csv-file");
    const fileName = $("csv-file-name");
    if (fileInput && fileName) {
      fileInput.addEventListener("change", () => {
        fileName.textContent = fileInput.files.length ? fileInput.files[0].name : "no file selected";
      });
      const fileButton = document.querySelector("label[for='csv-file']");
      if (fileButton) {
        fileButton.addEventListener("keydown", (event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            fileInput.click();
          }
        });
      }
      $("import-form").addEventListener("reset", () => {
        window.setTimeout(() => { fileName.textContent = "no file selected"; }, 0);
      });
    }

    const questionsFileInput = $("questions-json-file");
    const questionsFileName = $("questions-json-file-name");
    if (questionsFileInput && questionsFileName) {
      questionsFileInput.addEventListener("change", () => {
        questionsFileName.textContent = questionsFileInput.files.length ? questionsFileInput.files[0].name : "no file selected";
      });
      const questionsFileButton = document.querySelector("label[for='questions-json-file']");
      if (questionsFileButton) {
        questionsFileButton.addEventListener("keydown", (event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            questionsFileInput.click();
          }
        });
      }
      $("question-pack-upload-form").addEventListener("reset", () => {
        window.setTimeout(() => { questionsFileName.textContent = "no file selected"; }, 0);
      });
    }

    const mergeFileInput = $("merge-questions-json-files");
    const mergeFileName = $("merge-questions-json-file-name");
    if (mergeFileInput && mergeFileName) {
      mergeFileInput.addEventListener("change", () => {
        if (!mergeFileInput.files.length) {
          mergeFileName.textContent = "no file selected";
        } else if (mergeFileInput.files.length === 1) {
          mergeFileName.textContent = mergeFileInput.files[0].name;
        } else {
          mergeFileName.textContent = `${mergeFileInput.files.length} files selected`;
        }
      });
      const mergeFileButton = document.querySelector("label[for='merge-questions-json-files']");
      if (mergeFileButton) {
        mergeFileButton.addEventListener("keydown", (event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            mergeFileInput.click();
          }
        });
      }
      $("question-pack-merge-form").addEventListener("reset", () => {
        window.setTimeout(() => { mergeFileName.textContent = "no file selected"; }, 0);
      });
    }

    const aiToggle = $("ai-chat-toggle");
    const aiClose = $("ai-chat-close");
    if (aiToggle) aiToggle.addEventListener("click", () => toggleAiChat());
    if (aiClose) aiClose.addEventListener("click", () => toggleAiChat(false));

    const debugLayer = $("debug-modal-layer");
    const debugClose = $("debug-modal-close");
    const debugKey = `tmkctl-debug-ack:${state.workspaceId || "global"}`;
    if (debugLayer && window.sessionStorage.getItem(debugKey) === "1") {
      debugLayer.classList.add("hidden");
    }
    if (debugLayer && debugClose) {
      debugClose.addEventListener("click", () => {
        window.sessionStorage.setItem(debugKey, "1");
        debugLayer.classList.add("hidden");
      });
      debugClose.focus();
    }

    const time = $("global-time");
    if (time) {
      const updateTime = () => {
        const now = new Date();
        time.textContent = now.toLocaleTimeString("cs-CZ", { hour: "2-digit", minute: "2-digit" });
        time.setAttribute("datetime", now.toISOString());
      };
      updateTime();
      setInterval(updateTime, 30000);
    }

    setInterval(() => {
      if (state.noteDirty) saveNote(false);
    }, 8000);
    setInterval(() => {
      if (!state.noteDirty) refreshCurrentNote();
    }, 5000);
    setInterval(() => {
      api("api/heartbeat.php").catch(() => {});
    }, 30000);
  }

  renderQuestionSelect();
  initEvents();
  renderAll();
  refreshStudents().catch(() => {});
  refreshStack().catch(() => {});
})();
