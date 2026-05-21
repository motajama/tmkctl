# Shared Workspaces

After login, tmkctl opens **VÝBĚR RELACE / TERMÍNU** instead of the dashboard. A workspace is one shared exam run. Users can join an active workspace or create a new one.

Active workspaces are shown only while at least one connected browser has fresh presence. Presence is refreshed on dashboard load and by `public/api/heartbeat.php` about every 30 seconds. Stale presence is hidden after a short TTL, so inactive workspaces disappear from the selection list without deleting their data.

## Client Identity

The app assigns a persistent `tmkctl_client_id` cookie after login. It uses a random 32-character hex ID, `SameSite=Lax`, and `HttpOnly`. `Secure` is enabled automatically on HTTPS requests.

Cookies must be enabled. If the app cannot read the client cookie on the workspace selection screen, it shows:

```text
Pro sdílené relace musí být v prohlížeči povolené cookies. Bez cookies nelze spolehlivě určit připojeného uživatele.
```

The dashboard and mutable APIs require authentication, a valid client cookie, and a selected workspace.

## Scoped Data

These exam-run tables are workspace-scoped:

- `students`
- `exam_stack`
- `exam_notes`
- workspace settings such as `current_exam_label`

Reset affects only the current workspace. Exports include only the current workspace. Questions remain global and continue to load from:

```text
data/questions.reviewed.json
```

Question pack validation, upload, and merge are global question-management operations.

## Debug Warning

When config `debug` is true, the dashboard shows a red warning window after entering a workspace. The body includes trusted plain text from:

```text
data/debug-stage.txt
```

If the file is missing, the app shows a safe fallback message. Closing the warning hides it for the current browser session and workspace view.
