# AI Engineering Harness — Quick Reference

This folder controls the AI coding agent for the Frontend MVP phase.

## Core Files

| File | Purpose |
|------|---------|
| `CURRENT_TASK.md` | The **only** task the agent is allowed to work on right now |
| `PROGRESS.md` | Overall checklist and completion log |
| `BACKLOG.md` | Full scope of the 3-day phase |
| `ARCHITECTURE_CONSTRAINTS.md` | Hard rules that must never be broken |
| `VERIFICATION_LOG.md` | Optional log of self-checks |

## Mandatory Workflow (Task Execution Loop)

1. **Load Context** — Read `CURRENT_TASK.md` + `ARCHITECTURE_CONSTRAINTS.md` + relevant existing code
2. **Plan** — List files to create/change + restate Acceptance Criteria
3. **Implement** — Write the code
4. **Self-Verify** — Check every Acceptance Criterion
5. **Update State** — Update `PROGRESS.md` and replace `CURRENT_TASK.md` with the next task

## Critical Rules

- Work on **only one task** at a time.
- Never modify Core / Engine / API / Licensing / Queue.
- Always reuse Calculator, API_Manager, Formatter, License_Guard.
- Public output must respect the license gate.
- After finishing a task → update progress → stop (do not auto-start next task unless instructed).

## Recommended Prompt to Start a Task

```text
Read .ai/HARNESS.md, .ai/CURRENT_TASK.md, .ai/ARCHITECTURE_CONSTRAINTS.md and .ai/BACKLOG.md.

Execute ONLY the current task using the Task Execution Loop.
After verification, update PROGRESS.md and CURRENT_TASK.md, then stop.
```

## Full Documentation

See the complete harness document:  
`FPS-AI-Engineering-Harness.md` (in project docs or artifacts)
