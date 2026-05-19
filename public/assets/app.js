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
    noteDirty: false
  };

  const $ = (id) => document.getElementById(id);
  const questionById = (id) => state.questions.find((q) => q.id === id) || null;
  const studentById = (id) => state.students.find((s) => Number(s.id) === Number(id)) || null;
  const stackByStudentId = (id) => state.stack.find((item) => Number(item.student_id) === Number(id)) || null;
  const studyBadge = (type) => type === "single" ? "1OBOR" : (type === "double" ? "2OBOR" : "?");
  const states = [["waiting", "ČEKÁ"], ["preparing", "POTÍTKO"], ["examining", "ZKOUŠEN/A"], ["done", "HOTOVO"]];

  function escapeHtml(value) {
    return String(value || "")
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  function showMessage(message, type) {
    const box = $("messages");
    if (!box) return;
    box.innerHTML = message ? `<div class="message ${type || "success"}">${escapeHtml(message)}</div>` : "";
  }

  function setStatus(message, type) {
    $("save-status").innerHTML = message ? `<span class="${type || "success"}">${escapeHtml(message)}</span>` : "";
  }

  async function api(url, options) {
    const response = await fetch(url, options);
    const payload = (response.headers.get("content-type") || "").includes("application/json")
      ? await response.json()
      : { ok: response.ok };
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || "Akce selhala.");
    }
    return payload;
  }

  function postForm(url, data) {
    data.append("csrf_token", state.csrfToken);
    return api(url, { method: "POST", body: data });
  }

  function renderStudents() {
    $("students-list").innerHTML = state.students.map((s) => `
      <div class="student-row${Number(s.id) === Number(state.activeStudentId) ? " active" : ""}">
        <button type="button" data-student="${escapeHtml(s.id)}" class="student-main">
          <strong>${escapeHtml(s.name)}</strong>
          <span>[${escapeHtml(studyBadge(s.study_type))}] ${escapeHtml(s.uco || "bez UČO")}</span>
        </button>
        <button type="button" data-stack-student="${escapeHtml(s.id)}">FRONTA</button>
      </div>
    `).join("");
    document.querySelectorAll("[data-student]").forEach((button) => button.addEventListener("click", () => setActiveStudent(button.dataset.student)));
    document.querySelectorAll("[data-stack-student]").forEach((button) => button.addEventListener("click", () => addToStack(button.dataset.stackStudent)));
  }

  function renderStack() {
    const board = $("stack-board");
    board.innerHTML = "";
    states.forEach(([stateName, label]) => {
      const items = state.stack.filter((item) => item.state === stateName);
      const column = document.createElement("section");
      column.className = "stack-column";
      column.innerHTML = `<h3>${escapeHtml(label)} (${items.length})</h3>`;
      items.forEach((item) => {
        const assigned = questionById(item.question_id);
        const card = document.createElement("div");
        card.className = "stack-card" + (Number(item.student_id) === Number(state.activeStudentId) ? " active" : "");
        card.innerHTML = `
          <button type="button" class="stack-name" data-active="${escapeHtml(item.student_id)}">${Number(item.student_id) === Number(state.activeStudentId) ? "▶ " : ""}${escapeHtml(item.name)}</button>
          <div>[${escapeHtml(studyBadge(item.study_type))}] ${escapeHtml(item.uco || "bez UČO")}</div>
          <div class="stack-question">${escapeHtml(assigned ? `${assigned.short_title || assigned.title} (${item.question_id})` : (item.question_id ? `neplatná otázka: ${item.question_id}` : "bez otázky"))}</div>
          <div class="button-row">
            ${moveButtons(item)}
            <button type="button" data-random="${escapeHtml(item.id)}">losovat</button>
            <button type="button" data-active="${escapeHtml(item.student_id)}">aktivní</button>
          </div>
          <select data-assign="${escapeHtml(item.id)}" aria-label="Vybrat otázku">
            <option value="">bez otázky</option>
            ${state.questions.map((q) => `<option value="${escapeHtml(q.id)}"${q.id === item.question_id ? " selected" : ""}>${escapeHtml(q.id)} · ${escapeHtml(q.short_title || q.title)}</option>`).join("")}
          </select>
        `;
        column.appendChild(card);
      });
      board.appendChild(column);
    });
    document.querySelectorAll("[data-move]").forEach((button) => button.addEventListener("click", () => moveStack(button.dataset.stack, button.dataset.move)));
    document.querySelectorAll("[data-random]").forEach((button) => button.addEventListener("click", () => randomQuestion(button.dataset.random)));
    document.querySelectorAll("[data-active]").forEach((button) => button.addEventListener("click", () => setActiveStudent(button.dataset.active)));
    document.querySelectorAll("[data-assign]").forEach((select) => select.addEventListener("change", () => assignQuestion(select.dataset.assign, select.value)));
  }

  function moveButtons(item) {
    const allowed = {
      waiting: [["preparing", "potítko"]],
      preparing: [["examining", "zkoušet"], ["waiting", "zpět"]],
      examining: [["done", "hotovo"], ["preparing", "zpět"]],
      done: [["examining", "vrátit"]]
    };
    return (allowed[item.state] || []).map(([next, label]) => `<button type="button" data-stack="${escapeHtml(item.id)}" data-move="${next}">${label}</button>`).join("");
  }

  function renderQuestionSelect() {
    const select = $("manual-question-select");
    select.innerHTML = state.questions.map((q) => `<option value="${escapeHtml(q.id)}">${escapeHtml(q.id)} · ${escapeHtml(q.short_title || q.title)}</option>`).join("");
    state.manualQuestionId = state.manualQuestionId || (state.questions[0] && state.questions[0].id) || null;
    select.value = state.manualQuestionId || "";
  }

  function selectedQuestionId() {
    if (state.mode === "manual") return state.manualQuestionId;
    const item = stackByStudentId(state.activeStudentId);
    return item ? item.question_id : null;
  }

  function renderQuestion() {
    const panel = $("question-panel");
    $("manual-warning").classList.toggle("hidden", state.mode !== "manual");
    $("manual-question-select").disabled = state.mode !== "manual";
    $("back-to-active").disabled = state.mode !== "manual";

    if (state.questionsError) {
      panel.innerHTML = `<p>${escapeHtml(state.questionsError)}</p>`;
      renderNoteContext();
      return;
    }

    const questionId = selectedQuestionId();
    const question = questionById(questionId);
    if (!question) {
      panel.innerHTML = `<p>${questionId ? "Přiřazená otázka nebyla nalezena v JSON." : "Aktivní studující nemá přiřazenou otázku."}</p>`;
      renderNoteContext();
      return;
    }

    panel.innerHTML = `
      <div class="mode-line">${state.mode === "manual" ? "RUČNÍ VÝBĚR" : "OTÁZKA AKTIVNÍHO STUDUJÍCÍHO"}</div>
      <h1>${escapeHtml(question.title)}</h1>
      <div>${escapeHtml(question.short_title || "")}</div>
      ${(!question.source_refs || question.source_refs.length === 0) ? '<div class="message warning">Bez ověřených zdrojů — placeholder.</div>' : ""}
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
    return items && items.length ? `<h2>${escapeHtml(title)}</h2><ul>${items.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>` : "";
  }

  function renderTerms(items) {
    return items && items.length ? `<h2>Klíčové pojmy</h2>${items.map((item) => `<p><strong>[${escapeHtml(item.term)}]</strong> ${escapeHtml(item.definition)} ${item.authors && item.authors.length ? escapeHtml(item.authors.join(", ")) : ""}</p>`).join("")}` : "";
  }

  function renderAuthors(items) {
    return items && items.length ? `<h2>Autoři</h2><ul>${items.map((item) => `<li><strong>${escapeHtml(item.name)}</strong>: ${escapeHtml(item.role)}</li>`).join("")}</ul>` : "";
  }

  function renderNoteContext() {
    const student = studentById(state.activeStudentId);
    const question = questionById(selectedQuestionId());
    let message = "";
    if (!student) message = "Chybí aktivní studující.";
    else if (!question) message = "Chybí vybraná nebo přiřazená otázka.";
    else message = `${student.name} · ${student.uco || "bez UČO"} · ${question.short_title || question.title}`;
    $("note-context").textContent = message;
    $("note-text").disabled = !student || !question;
    $("suggested-grade").disabled = !student || !question;
  }

  async function loadNote() {
    renderNoteContext();
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    if (!studentId || !questionById(questionId)) {
      $("note-text").value = "";
      $("suggested-grade").value = "";
      return;
    }
    try {
      const payload = await api(`api/notes.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId)}`);
      $("note-text").value = payload.note.note_text || "";
      $("suggested-grade").value = payload.note.suggested_grade || "";
      state.noteDirty = false;
      setStatus("");
    } catch (error) {
      setStatus(error.message, "error");
    }
  }

  async function saveNote(manual) {
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    if (!studentById(studentId) || !questionById(questionId)) {
      setStatus("Nelze uložit: chybí aktivní studující nebo otázka.", "error");
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
      setStatus(error.message, "error");
    }
  }

  async function refreshStack(payload) {
    if (!payload) payload = await api("api/stack.php");
    state.stack = payload.stack;
    state.activeStudentId = payload.activeStudentId;
    renderAll();
  }

  async function setActiveStudent(studentId) {
    const data = new FormData();
    data.append("action", "active");
    data.append("student_id", studentId);
    try {
      const payload = await postForm("api/stack.php", data);
      showMessage(payload.message);
      refreshStack(payload);
    } catch (error) {
      showMessage(error.message, "error");
    }
  }

  async function addToStack(studentId) {
    const data = new FormData();
    data.append("action", "add");
    data.append("student_id", studentId);
    try {
      const payload = await postForm("api/stack.php", data);
      showMessage(payload.message);
      refreshStack(payload);
    } catch (error) {
      showMessage(error.message, "error");
    }
  }

  async function moveStack(stackId, nextState) {
    const data = new FormData();
    data.append("action", "move");
    data.append("stack_id", stackId);
    data.append("state", nextState);
    try {
      const payload = await postForm("api/stack.php", data);
      showMessage(payload.message);
      refreshStack(payload);
    } catch (error) {
      showMessage(error.message, "error");
    }
  }

  async function assignQuestion(stackId, questionId) {
    const data = new FormData();
    data.append("action", "assign");
    data.append("stack_id", stackId);
    data.append("question_id", questionId);
    try {
      const payload = await postForm("api/stack.php", data);
      showMessage(payload.message);
      refreshStack(payload);
    } catch (error) {
      showMessage(error.message, "error");
    }
  }

  async function randomQuestion(stackId) {
    const data = new FormData();
    data.append("action", "random_assign");
    data.append("stack_id", stackId);
    try {
      const payload = await postForm("api/stack.php", data);
      showMessage(payload.message);
      refreshStack(payload);
    } catch (error) {
      showMessage(error.message, "error");
    }
  }

  function download(format) {
    const studentId = state.activeStudentId;
    const questionId = selectedQuestionId();
    if (!studentById(studentId) || !questionById(questionId)) {
      setStatus("Nelze exportovat: chybí aktivní studující nebo otázka.", "error");
      return;
    }
    window.location.href = `api/export_note.php?student_id=${encodeURIComponent(studentId)}&question_id=${encodeURIComponent(questionId)}&format=${format}`;
  }

  function renderAll() {
    renderStudents();
    renderStack();
    renderQuestion();
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
        const extra = payload.result.errors.length ? " " + payload.result.errors.join(" ") : "";
        showMessage(payload.message + extra, payload.result.errors.length ? "warning" : "success");
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
    $("global-save").addEventListener("click", () => saveNote(true));
    $("copy-note").addEventListener("click", async () => {
      await navigator.clipboard.writeText($("note-text").value);
      setStatus("Poznámka zkopírována.");
    });
    $("download-txt").addEventListener("click", () => download("txt"));
    $("download-md").addEventListener("click", () => download("md"));
    $("global-export").addEventListener("click", () => download("txt"));
    setInterval(() => {
      if (state.noteDirty) saveNote(false);
    }, 8000);
  }

  renderQuestionSelect();
  initEvents();
  renderAll();
})();
