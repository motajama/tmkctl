# Development Seed Notes

The MVP does not insert exam questions into MySQL.

Questions are loaded read-only from:

```text
data/questions.reviewed.json
```

The database stores only students, exam stack state, examiner notes, and app settings. To change questions in the MVP, update `data/questions.reviewed.json` outside the web app and redeploy that file.
