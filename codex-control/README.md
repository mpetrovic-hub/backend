# Codex Control

This directory contains the control files and prompts for the Codex Overnight workflow.

Codex must not push directly to `main`.

Workflow phases:

1. Planner
   - Reads one GitHub Issue.
   - Analyzes the repository.
   - Writes a Planner Report as an Issue comment.
   - Does not change code.

2. Implementer
   - Runs only after a plan is approved.
   - Works on a separate branch.
   - Opens a Pull Request.

3. Reviewer
   - Reviews the Pull Request against the Issue and Planner Report.
   - Writes a review score and risk summary.