---
name: create-skill
description: "Guide the user to create a reusable workspace skill (SKILL.md) that captures a workflow or checklist."
argument-hint: "What workflow or outcome should this skill encode?"
---

Use this skill when you want to turn a repeatable project workflow into a reusable `SKILL.md` file.

## Workflow

1. Identify the target outcome.
   - What task or workflow should the skill automate or document?
   - Is the goal a checklist, a multi-step process, or a decision flow?

2. Determine scope.
   - Workspace-scoped skill: store under `.github/skills/<name>/SKILL.md`.
   - Personal skill: store in your user prompts folder instead.

3. Choose a clear skill name.
   - Use a short, descriptive identifier such as `create-skill`, `fix-audit`, or `build-report`.

4. Draft the skill file.
   - Add YAML frontmatter with `name`, `description`, and optional `argument-hint`.
   - Keep the description specific and trigger-oriented.
   - Write the body as a reusable workflow:
     - purpose and when to use it
     - step-by-step process
     - decision points and branching logic
     - completion criteria
     - example prompts or usage patterns

5. Validate and save.
   - Confirm the skill file exists at `.github/skills/<name>/SKILL.md`.
   - Verify YAML syntax and a meaningful `description`.
   - Ensure the body is clear, actionable, and easy to follow.

## Quality checks

- The skill should answer: "What does this skill do?" and "When should it be used?"
- It should make the workflow reproducible without extra context.
- It should include at least one example prompt or task description.

## Examples

- "Create a `SKILL.md` to standardize how we add new report features."
- "Capture the audit review workflow as a reusable skill."
- "Generate a skill that guides developers through asset import validation."
