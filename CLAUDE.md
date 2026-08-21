## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Changelog

Convencion adoptada del admin-web de Brewer Manager: **por cada cambio que se
haga en el proyecto, una entrada nueva en `panel/datos/changelog.php`**, arriba
del todo y subiendo la version.

- Los items le hablan a quien NO programa: dicen que cambio para el usuario y
  por que, no que archivo se toco. Un mensaje de commit y una entrada de
  changelog son dos textos distintos, y el segundo no se deduce del primero.
- El archivo solo devuelve un array (`<?php return [ ... ];`): sin logica, sin
  consultas, sin salida.
- Se ve en `/admin/changelog`, y su primera entrada alimenta la version y el
  bloque "Ultimo cambio" de la portada del panel de control.
