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
    mode: "follow",
    manualQuestionId: null,
    currentNoteKey: "",
    noteDirty: false,
    isImportStudents: []
  };

  const $ = (id) => document.getElementById(id);
  const questionById = (id) => state.questions.find((q) => q.id === id) || null;
  const studentById = (id) => state.students.find((s) => Number(s.id) === Number(id)) || null;
  const stackByStudentId = (id) => state.stack.find((item) => Number(item.student_id) === Number(id)) || null;
  const stackStates = [
    ["waiting", "FRONTA"],
    ["preparing", "POTÍTKO"],
    ["examining", "ZKOUŠENÍ"],
    ["done", "HOTOVO"]
  ];
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
    return String(value || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function showMessage(message, type) {
    const box = $("messages");
    if (!box) return;
    box.innerHTML = message ? `<div class="${type === "error" ? "alert" : "notice"}">${escapeHtml(message)}</div>` : "";
  }

  async function api(url, options) {
    const response = await fetch(url, options);
    const contentType = response.headers.get("content-type") || "";
    const payload = contentType.includes("application/json") ? await response.json() : { ok: response.ok };
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Požadavek selhal.");
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
    const focusTarget = modal.querySelector("input, textarea, select, button, a[href]");
    if (focusTarget) focusTarget.focus();
  }

  function closeModal() {
    const layer = $("modal-layer");
    if (layer) layer.classList.add("hidden");
    document.querySelectorAll(".modal-window").forEach((modal) => modal.classList.add("hidden"));
  }

  function examinedStudentIds() {
    return new Set(state.stack.filter((item) => item.state === "examining").map((item) => Number(item.student_id)));
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

  function renderStudents() {
    const box = $("students-list");
    box.innerHTML = "";
    const examined = examinedStudentIds();
    state.students.forEach((student) => {
      const isCurrent = Number(student.id) === Number(currentStudentId());
      const isExamined = examined.has(Number(student.id));
      const row = document.createElement("div");
      row.className = "student-row" + (isCurrent ? " current" : "") + (isExamined ? " examined" : "");
      row.innerHTML = `
        <button type="button" class="student-main">
          <strong><span class="cursor-marker">${isCurrent ? ">" : ""}</span>${isExamined ? "[ZK] " : ""}${escapeHtml(student.name)}</strong>
          <span><b class="study-badge">${escapeHtml(studyBadge(student.study_type))}</b>${escapeHtml(student.uco || "bez UČO")}</span>
        </button>
        <button type="button" class="mini-button">FRONTA</button>
      `;
      row.querySelector(".student-main").addEventListener("click", () => setActiveStudent(student.id));
      row.querySelector(".mini-button").addEventListener("click", () => addToStack(student.id));
      box.appendChild(row);
    });
  }

  function renderStack() {
    const board = $("stack-board");
    board.innerHTML = "";
    stackStates.forEach(([stackState, label]) => {
      const items = state.stack.filter((item) => item.state === stackState);
      const column = document.createElement("div");
      column.className = "stack-column";
      column.innerHTML = `<h3>${escapeHtml(label)} (${items.length})</h3>`;
      items.forEach((item) => {
        const card = document.createElement("div");
        const isCurrent = Number(item.student_id) === Number(currentStudentId());
        const isExamined = item.state === "examining";
        card.className = "stack-card" + (isCurrent ? " current" : "") + (isExamined ? " examined" : "");
        const assigned = questionById(item.question_id);
        card.innerHTML = `
          <div class="stack-row-main">
            <button type="button" class="stack-name"><span class="cursor-marker">${isCurrent ? ">" : ""}</span>${isExamined ? "[ZK] " : ""}${escapeHtml(item.name)}</button>
            <div class="stack-meta"><b class="study-badge">${escapeHtml(studyBadge(item.study_type))}</b>${escapeHtml(item.uco || "bez UČO")}</div>
          </div>
          <div class="stack-question">
            <span>${escapeHtml(assigned ? `${assigned.short_title || assigned.title} (${item.question_id})` : "bez otázky")}</span>
          </div>
          <div class="question-assign">
            <button type="button" data-action="random">losovat</button>
            <label>
              <span>vybrat</span>
              <select class="assign-select" aria-label="Vybrat otázku pro ${escapeHtml(item.name)}">
                <option value="">bez otázky</option>
                ${state.questions.map((q) => `<option value="${escapeHtml(q.id)}"${q.id === item.question_id ? " selected" : ""}>${escapeHtml(q.id)} · ${escapeHtml(q.short_title || q.title)}</option>`).join("")}
              </select>
            </label>
          </div>
          <div class="button-row tight">
            ${moveButtons(item)}
            <button type="button" data-action="choose">otázka</button>
            <button type="button" data-action="active">kurzor</button>
          </div>
        `;
        card.querySelector(".stack-name").addEventListener("click", () => setActiveStudent(item.student_id));
        const assignSelect = card.querySelector(".assign-select");
        assignSelect.addEventListener("change", (event) => assignQuestion(item.id, event.target.value));
        card.querySelectorAll("[data-move]").forEach((button) => {
          button.addEventListener("click", () => moveStack(item.id, button.dataset.move));
        });
        card.querySelector("[data-action='random']").addEventListener("click", () => randomQuestion(item.id));
        card.querySelector("[data-action='choose']").addEventListener("click", () => assignSelect.focus());
        card.querySelector("[data-action='active']").addEventListener("click", () => setActiveStudent(item.student_id));
        column.appendChild(card);
      });
      board.appendChild(column);
    });
  }

  function moveButtons(item) {
    const allowed = {
      waiting: [["preparing", "potítko"], ["examining", "zkoušení"], ["done", "hotovo"]],
      preparing: [["examining", "zkoušení"], ["done", "hotovo"], ["waiting", "fronta"]],
      examining: [["done", "hotovo"], ["preparing", "zpět"]],
      done: [["examining", "zkoušení"]]
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
    $("back-to-active").disabled = state.mode !== "manual";
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
      label.textContent = state.currentExamLabel ? `TERMÍN: ${state.currentExamLabel}` : "";
    }
  }

  async function loadNote() {
    renderNotesContext();
    const studentId = currentStudentId();
    const questionId = selectedQuestionId();
    const key = `${studentId || ""}:${questionId || ""}`;
    state.currentNoteKey = key;
    if (!studentId) {
      $("note-text").value = "";
      $("suggested-grade").value = "";
      $("note-text").disabled = true;
      $("suggested-grade").disabled = true;
      return;
    }
    $("note-text").disabled = false;
    $("suggested-grade").disabled = false;
    try {
      const payload = await api(`api/notes.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId || "")}`);
      if (state.currentNoteKey !== key) return;
      $("note-text").value = payload.note.note_text || "";
      $("suggested-grade").value = payload.note.suggested_grade || "";
      state.noteDirty = false;
      setStatus("");
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
    const data = new FormData();
    data.append("student_id", studentId);
    data.append("question_id", questionId || "");
    data.append("note_text", $("note-text").value);
    data.append("suggested_grade", $("suggested-grade").value);
    try {
      const payload = await postForm("api/notes.php", data);
      state.noteDirty = false;
      setStatus((payload.message || "Poznámka byla uložena.") + (manual ? "" : " (autosave)"));
    } catch (error) {
      setStatus(error.message);
    }
  }

  function setStatus(message) {
    $("save-status").textContent = message;
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

  async function moveStack(stackId, nextState) {
    const data = new FormData();
    data.append("action", "move");
    data.append("stack_id", stackId);
    data.append("state", nextState);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
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

  async function randomQuestion(stackId) {
    const data = new FormData();
    data.append("action", "random_assign");
    data.append("stack_id", stackId);
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
  }

  function setExportStatus(message) {
    const status = $("export-status");
    if (status) status.textContent = message || "";
  }

  function focusRelative(offset) {
    if (!state.students.length) {
      writeConsole("není koho vybrat");
      return;
    }
    const current = currentStudentId();
    let index = state.students.findIndex((student) => Number(student.id) === Number(current));
    if (index < 0) index = 0;
    index = Math.max(0, Math.min(state.students.length - 1, index + offset));
    setActiveStudent(state.students[index].id).catch((error) => writeConsole(error.message));
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
      writeConsole("příkazy: :help :import :reset :export :logout :focus next :focus prev :active :question active :question manual");
    } else if (command === "import") {
      openModal("import");
    } else if (command === "reset") {
      openModal("reset");
    } else if (command === "export") {
      openModal("export");
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
        const payload = await postForm("api/students.php", new FormData(event.target));
        state.students = payload.students;
        event.target.reset();
        showMessage(payload.message);
        renderStudents();
      } catch (error) {
        showMessage(error.message, "error");
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
        renderStudents();
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    const sessionLabelForm = $("session-label-form");
    if (sessionLabelForm) {
      sessionLabelForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        try {
          const payload = await postForm("api/session_label.php", new FormData(event.target));
          state.currentExamLabel = payload.currentExamLabel || "";
          $("current-exam-label").value = state.currentExamLabel;
          renderExamLabel();
          setOperationsStatus(payload.message || "Název termínu byl uložen.");
        } catch (error) {
          setOperationsStatus(error.message);
        }
      });
    }

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
          event.target.reset();
          $("current-exam-label").value = state.currentExamLabel;
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
    $("draw-current-question").addEventListener("click", () => {
      const item = currentStackItem();
      if (!item) {
        showMessage("Studující není ve frontě.", "error");
        return;
      }
      randomQuestion(item.id);
    });
    $("assign-current-question").addEventListener("click", () => {
      const item = currentStackItem();
      const questionId = $("manual-question-select").value || selectedQuestionId();
      if (!item || !questionId) {
        showMessage("Nelze přiřadit: chybí studující ve frontě nebo otázka.", "error");
        return;
      }
      assignQuestion(item.id, questionId);
    });

    $("back-to-active").addEventListener("click", () => {
      state.mode = "follow";
      renderQuestion();
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
    $("global-import").addEventListener("click", () => openModal("import"));
    $("open-import-inline").addEventListener("click", () => openModal("import"));
    $("global-reset").addEventListener("click", () => openModal("reset"));
    $("global-export-all").addEventListener("click", () => openModal("export"));
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
  }

  renderQuestionSelect();
  initEvents();
  renderAll();
  refreshStudents().catch(() => {});
  refreshStack().catch(() => {});
})();
