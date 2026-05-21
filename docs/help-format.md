# Help Content Format

The help window loads trusted project content from:

```text
public/assets/help.html
```

This file is an HTML fragment only. Do not include `<html>`, `<head>`, `<body>`, or CSS. The dashboard supplies the window and styling.

Allowed basic tags include:

- headings: `<h2>`, `<h3>`
- text: `<p>`, `<strong>`, `<em>`, `<code>`, `<pre>`
- lists: `<ul>`, `<ol>`, `<li>`
- images: `<img>`
- tables: `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`

Store help images under:

```text
public/assets/help/
```

Reference them from `help.html` relative to the public directory:

```html
<h2>Import studujících</h2>
<p>Postup importu z IS MU.</p>
<img src="assets/help/import.png" alt="Ukázka importu">
```

Do not add user-uploaded HTML here. The fragment is trusted repository content.
