(function () {
  const state = {
    csrfToken: window.TMKCTL.csrfToken,
    questions: window.TMKCTL.questions || [],
    questionsError: window.TMKCTL.questionsError || "",
    students: window.TMKCTL.students || [],
    stack: window.TMKCTL.stack || [],
    activeStudentId: window.TMKCTL.activeStudentId || null,
    mode: "follow",
    manualQuestionId: null,
    currentNoteKey: "",
    noteDirty: false
  };

  const $ = (id) => document.getElementById(id);
  const questionById = (id) => state.questions.find((q) => q.id === id) || null;
  const studentById = (id) => state.students.find((s) => Number(s.id) === Number(id)) || null;
  const stackByStudentId = (id) => state.stack.find((item) => Number(item.student_id) === Number(id)) || null;
  const stackStates = [
    ["waiting", "ČEKÁ"],
    ["preparing", "POTÍTKO"],
    ["examining", "ZKOUŠEN/A"],
    ["done", "HOTOVO"]
  ];
  const studyBadge = (studyType) => {
    if (studyType === "single") return "1OBOR";
    if (studyType === "double") return "2OBOR";
    return "?";
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

  function renderStudents() {
    const box = $("students-list");
    box.innerHTML = "";
    state.students.forEach((student) => {
      const row = document.createElement("div");
      row.className = "student-row" + (Number(student.id) === Number(state.activeStudentId) ? " active" : "");
      row.innerHTML = `
        <button type="button" class="student-main">
          <strong>${escapeHtml(student.name)}</strong>
          <span><b class="study-badge">${escapeHtml(studyBadge(student.study_type))}</b>${escapeHtml(student.uco || "bez UČO")}</span>
        </button>
        <button type="button" class="mini-button">STACK</button>
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
        card.className = "stack-card" + (Number(item.student_id) === Number(state.activeStudentId) ? " active" : "");
        const assigned = questionById(item.question_id);
        card.innerHTML = `
          <div class="stack-row-main">
            <button type="button" class="stack-name">${Number(item.student_id) === Number(state.activeStudentId) ? "▶ " : ""}${escapeHtml(item.name)}</button>
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
            <button type="button" data-action="active">aktivní</button>
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
      waiting: [["preparing", "potítko"]],
      preparing: [["examining", "zkoušet"], ["waiting", "zpět"]],
      examining: [["done", "hotovo"], ["preparing", "zpět"]],
      done: [["examining", "vrátit"]]
    };
    return (allowed[item.state] || []).map(([next, label]) => `<button type="button" data-move="${next}">${label}</button>`).join("");
  }

  function selectedQuestionId() {
    if (state.mode === "manual") {
      return state.manualQuestionId || (state.questions[0] && state.questions[0].id) || null;
    }
    const stackItem = stackByStudentId(state.activeStudentId);
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
    const student = studentById(state.activeStudentId);
    const question = questionById(selectedQuestionId());
    $("note-context").textContent = student && question
      ? `${student.name} · ${student.uco || "bez UČO"} · ${question.short_title || question.title}`
      : (!student ? "Chybí aktivní studující." : "Chybí vybraná nebo přiřazená otázka.");
    const globalActive = $("global-active-student");
    if (globalActive) {
      globalActive.textContent = student ? `AKTIVNÍ: ${student.name}` : "";
    }
  }

  async function loadNote() {
    renderNotesContext();
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    const key = `${studentId || ""}:${questionId || ""}`;
    state.currentNoteKey = key;
    if (!studentId || !questionById(questionId)) {
      $("note-text").value = "";
      $("suggested-grade").value = "";
      $("note-text").disabled = true;
      $("suggested-grade").disabled = true;
      return;
    }
    $("note-text").disabled = false;
    $("suggested-grade").disabled = false;
    try {
      const payload = await api(`api/notes.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId)}`);
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
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    if (!studentById(studentId) || !questionById(questionId)) {
      setStatus("Nelze uložit: chybí aktivní studující nebo otázka.");
      return;
    }
    const data = new FormData();
    data.append("student_id", studentId);
    data.append("question_id", questionId);
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
    renderAll();
  }

  async function setActiveStudent(studentId) {
    const data = new FormData();
    data.append("action", "active");
    data.append("student_id", studentId);
    const payload = await postForm("api/stack.php", data);
    showMessage(payload.message);
    state.activeStudentId = payload.activeStudentId;
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
  }

  function download(format) {
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    if (!studentById(studentId) || !questionById(questionId)) {
      setStatus("Nelze exportovat: chybí aktivní studující nebo otázka.");
      return;
    }
    window.location.href = `api/export_note.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId)}&format=${format}`;
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
        event.target.reset();
        const extra = payload.result && payload.result.errors && payload.result.errors.length ? " " + payload.result.errors.join(" ") : "";
        showMessage((payload.message || `Importováno: ${payload.imported}`) + extra, extra ? "error" : "success");
        renderStudents();
      } catch (error) {
        showMessage(error.message, "error");
      }
    });

    document.querySelectorAll("input[name='question_mode']").forEach((input) => {
      input.addEventListener("change", () => {
        state.mode = input.value;
        renderQuestion();
      });
    });

    $("manual-question-select").addEventListener("change", (event) => {
      state.manualQuestionId = event.target.value;
      renderQuestion();
    });

    $("back-to-active").addEventListener("click", () => {
      state.mode = "follow";
      document.querySelector("input[name='question_mode'][value='follow']").checked = true;
      renderQuestion();
    });

    $("note-text").addEventListener("input", () => { state.noteDirty = true; });
    $("suggested-grade").addEventListener("input", () => { state.noteDirty = true; });
    $("save-note").addEventListener("click", () => saveNote(true));
    $("copy-note").addEventListener("click", async () => {
      await navigator.clipboard.writeText($("note-text").value);
      setStatus("Zkopírováno");
    });
    $("download-txt").addEventListener("click", () => download("txt"));
    $("download-md").addEventListener("click", () => download("md"));
    const globalSave = $("global-save");
    if (globalSave) {
      globalSave.addEventListener("click", () => saveNote(true));
    }
    const globalExport = $("global-export");
    if (globalExport) {
      globalExport.addEventListener("click", () => download("txt"));
    }
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
