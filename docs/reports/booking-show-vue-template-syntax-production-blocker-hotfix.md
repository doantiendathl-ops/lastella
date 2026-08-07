# Booking Show Vue Template Syntax Production Blocker Hotfix

## 1. Incident

A Production Readiness Audit reported that `resources/js/Pages/Admin/Bookings/Show.vue` on `origin/phase-3` (M5 checkpoint `725b593776726784f0504ab2c9e58bada36d00d7`) fails to parse as a valid Vue Single-File Component under `@vue/compiler-sfc`, with 3 reported errors around lines 1306, 1454, and 1650 of the full SFC file. This is a genuine production blocker: any deployment building from a clean checkout of `origin/phase-3` would fail at the Vite/Vue-compile step for this file.

## 2. Reproduction

Extracted `git show HEAD:resources/js/Pages/Admin/Bookings/Show.vue` and parsed it directly with the repository's own `@vue/compiler-sfc` (via `parse()` and `compileTemplate()`), independent of any assumption:

```
=== HEAD ===
SFC PARSE ERRORS: 3
  - Element is missing end tag. @ line 1306, col 17
  - Invalid end tag. @ line 1454, col 17
  - Invalid end tag. @ line 1650, col 13
TEMPLATE COMPILE ERRORS: 3 (same, at template-relative line numbers)
```

**Reproduced exactly as reported.** The current uncommitted working tree, by contrast, parses with **0 errors** — the difference is one specific line already present (uncommitted) in the working tree.

## 3. Root Cause

**Not an extra/duplicate closing tag — a missing opening tag in the committed history.**

`git blame` on the orphaned closing tag traced it to Milestone 3's own commit:

```
a331ef5d (Tien Dat Doan 2026-08-06 15:20:27 +0700 1453)                     </div>
```

`git show a331ef5 -- Show.vue` confirms M3 itself *added* this `</div>` as new content, immediately before the pre-existing `</form>`:

```
                                 </div>
                         </section>
                     </div>
+                    </div>
                 </form>
```

At the time M3 was authored, a "Sơ đồ phòng" (Room Board) mobile-overflow wrapper `<div class="overflow-x-auto pb-72 sm:overflow-visible sm:pb-0">` already existed in the working tree as an **uncommitted, pre-existing local change** (predating this entire Room Demand/Room Board Unification project). M3's author correctly saw, in their own working tree, that the demand-first form needed one more closing `</div>` to balance against that wrapper — and committed the closing tag. But the wrapper's own **opening tag was never committed** (it stayed local-only, correctly excluded from every milestone's selective-staging discipline as "pre-existing, out of scope"). The result: every commit from M3 onward has carried an orphaned closing `</div>` with no matching opener in the committed history — a latent defect invisible in every local session because every local working tree, this whole project, always had that same uncommitted opening tag present, masking it.

**Cascade explanation:** once the parser consumes the extra unmatched `</div>` at line 1453, its open-tag stack is off by one for everything that follows. The very next closing tag, `</form>` (line 1454), no longer matches the stack's top entry → "Invalid end tag." The imbalance propagates through the rest of the template, surfacing again at the M4 Bulk Release panel's own closing tags (line 1650) — not a separate, independent bug, the same single root cause cascading.

**One structural tag error, not three independent ones** — confirmed empirically: adding back exactly the one missing opening tag resolves all 3 reported errors (§5).

## 4. Exact Fix

Add exactly one line, immediately before the existing `<div class="space-y-1.5">` that starts the room-board floors loop:

```vue
<div class="overflow-x-auto pb-72 sm:overflow-visible sm:pb-0">
```

**No file edit was required this session** — this exact line already exists in the current uncommitted working tree, as part of what this project's prior sessions catalogued as "pre-existing local hunk 2" (the Room Board overflow/mobile-scroll wrapper). That hunk also includes an explanatory comment block above the tag; the comment is not required for parsing but documents why the wrapper exists (tooltip clearance on mobile) and is reasonable to keep when this fix is eventually committed.

**Important reclassification:** this one line is **not** discretionary responsive polish — it is a required structural fix for a real defect present in `origin/phase-3` since M3. It must be committed (in a future, separate commit-closure task, per this task's own "chưa commit" instruction) as an explicit hotfix, decoupled from the unrelated header-responsive hunk (which remains genuinely optional local work). The explanatory comment may be committed alongside it or not — a minor decision for the commit-closure task, not this one.

## 5. Why It Is Minimal

Verified in isolation: a copy of `origin/phase-3`'s HEAD content with **only** this single line inserted (no comment, no header-responsive hunk, nothing else) was parsed independently and produced **0 errors**:

```
=== MINIMAL FIX TEST (HEAD + only the 1 missing opening div tag) ===
SFC PARSE OK — 0 errors
TEMPLATE COMPILE OK — 0 errors
```

No class changed, no text changed, no `v-if` changed, no submit handler changed, no business logic touched, no reordering, no whitespace reformatting elsewhere in the file.

## 6. Build Result

`npm run build`: **success**, no errors (2383 modules transformed, `built in 19.74s`). Working tree already contains the fix, so this build reflects the corrected state.

## 7. Regression Tests

| Suite | Result |
|---|---|
| M2 (`RoomAssignmentAtomicMappingTest`) | 27/27 PASS |
| M3 (`RoomAssignmentFromRoomBoardTest`) | 29/29 PASS |
| M4 (`BulkRoomReleaseTest`) | 45/45 PASS |
| `BookingEngineFoundationTest` | 25/25 PASS |
| `BookingManagementUiTest` (Booking render/UI) | 154 passed, 11 failed — the same known pre-existing/time-dependent baseline (backend Inertia prop assertions, unrelated to this template's structure), byte-for-byte identical to the baseline documented since M2 |

No code was changed this session, so these results confirm the working tree (already containing the fix) has no regression — they do not exercise anything new.

## 8. Browser QA

Product Owner re-authenticated the Local browser session. Real, DOM-verified QA was performed against the live Local dev server (booking 473, "QA-M5-2YLPPQ", `http://127.0.0.1:8000/admin/bookings/473?tab=room_map`) via direct JavaScript/DOM interaction (element counts, `closest('form')` containment checks, before/after visibility state on real dispatched `Escape` `KeyboardEvent`s) — not screenshots, not visual inspection alone.

| Case | Description | Result |
|---|---|---|
| 1 | Booking detail: full load, no blank page, no Vue runtime error, no console error, sections after Room Board still visible | **PASS** — page rendered end-to-end (Room Board, PHÂN PHÒNG table rooms 103/104, NHẬN PHÒNG-TRẢ PHÒNG section); no error text; no console errors |
| 2 | Demand-first: form renders, room list nested correctly, selects/inputs inside form, no control escapes container, can select requirement/room, submit state works | **PASS** — single demand-first `<form>` found (action → room_map tab, contains "Lưu phân phòng"); selecting room 204 kept the submit button correctly nested inside the form (`selectsOutsideAnyForm: 0`); state reset after test |
| 3 | Room Board: renders, horizontal scroll only within expected wrapper, no double scrollbar, no cut-off room cards, no wrapper nesting error, desktop renders correctly | **PASS** — `.overflow-x-auto.pb-72` wrapper found containing exactly 8 floor `<section>` elements and all 59 room cards on the page (`roomCardCountInsideWrapper: 59 === totalRoomCardsOnPage: 59`); `floorsContainerIsDirectChild: true` — no structural escape |
| 4 | Room-Board-first: panel opens, room/group list correct, price/source/note/guest fields correct, submit/cancel correct, no panel outside expected position | **PASS** — selecting room 204 opened the M3 panel ("Xác nhận & đồng bộ nhu cầu"); `panelInsideForm: false` (correctly a sibling of the demand-first form, matching source architecture, not nested inside it); price/price_source/guest-count/note/checkbox inputs and submit/cancel buttons all present |
| 5 | Bulk Release: select assigned room, panel opens, reason/note/reduce-demand controls render, ESC closes panel (M5 fix) | **PASS** — checking an assignment checkbox + "Gỡ các phòng đã chọn" opened the bulk-release panel with reason textarea, reduce-demand checkbox, "Xác nhận gỡ phòng" button; a real dispatched `Escape` `KeyboardEvent` closed the panel (`escClosedPanel: true`) — M5 ESC fix confirmed still working |
| 6 | Single release/existing UI: still works/renders, no nesting conflict with Bulk Release panel | **PASS** — clicking a row's "Giải phóng" button opened the single-release dialog; bulk-release panel was not also visible (`bulkPanelStillOrAlsoVisible: false`); a real `Escape` event also closed the single-release dialog (`releaseDialogClosedByEsc: true`) — no nesting conflict, both ESC behaviors intact |
| 7 | Responsive/structural wrapper at 1366×768 and 390×844 | **NOT VERIFIED — browser tool limitation.** `mcp__claude-in-chrome__resize_window` reported success ("Successfully resized window containing tab to 390x844 pixels") but `window.innerWidth`/`innerHeight` remained unchanged (`1707×932`, the pre-resize value) when checked immediately after via `javascript_tool`. This is a known, repeatedly-confirmed limitation of this environment (observed consistently across every prior viewport-testing attempt in this project) — the tool does not actually change the real viewport. Per instruction, this is reported honestly as unverified rather than a fabricated PASS. |

**PRODUCT OWNER MANUAL VIEWPORT CHECK REQUIRED** for Case 7: please open `http://127.0.0.1:8000/admin/bookings/473?tab=room_map` directly and, using real browser window resize or DevTools device toolbar, confirm at both **1366×768** and **390×844**:
- [ ] Room Board horizontal scroll stays contained inside its own wrapper (no page-level double scrollbar)
- [ ] No room card is cut off or escapes the wrapper
- [ ] Demand-first form and Room-Board-first panel remain usable/nested correctly at narrow width
- [ ] No layout break in sections after the Room Board

Cases 1–6 (all required, non-viewport-specific checks) are real, DOM-verified **PASS**. Case 7 is the only unverified item, due to a tool limitation, not a code defect — the DOM-level check in Case 3 already confirms the wrapper's containment structurally; only the *rendered* responsive behavior at specific breakpoints needs a human/real-browser confirmation.

## 9. Local Hunks Preserved

`git diff -- resources/js/Pages/Admin/Bookings/Show.vue` still shows exactly the same 2 hunks as before this task (header responsive row; Room Board overflow wrapper, which now carries the reclassification noted in §4) — **nothing was added, removed, or modified** by this task. No file edit was made.

## 10. Files Changed

**None.** This report is the only new file this task produced. The fix already existed, uncommitted, in the working tree from before this task began.

## 11. Deployment Impact

This defect has been present in `origin/phase-3` since Milestone 3's commit (`a331ef5`) and has therefore been present through M4 and M5's pushed checkpoints as well — any deployment attempting to build the frontend from a clean checkout of `origin/phase-3` at any point since M3 would have failed at the Vite build step. It was never caught locally because every development session's working tree carried the same uncommitted opening-tag hunk. This is now understood and the exact minimal fix is verified; it has not yet been committed (out of this task's scope).

Two Service Package commits noted in the earlier production-audit's 7-commit deployment delta (`5b594ec`, `e9ffea6`) are unrelated to this fix and were not touched.

## 12. Readiness

**BROWSER QA CASES 1–6 PASS — CASE 7 (VIEWPORT) PENDING PRODUCT OWNER CONFIRMATION — HOTFIX COMMIT ON HOLD**

Root cause fully traced to a specific historical commit (M3, `a331ef5`) with concrete `git blame`/`git show` evidence. The minimal fix (exactly one line) is verified in isolation via `@vue/compiler-sfc` to fully resolve all 3 reported parse errors, with zero other changes. It already exists, uncommitted, in the current working tree. Build passes. M2/M3/M4/BookingEngineFoundation regression suites are clean; `BookingManagementUiTest`'s 11 failures are the pre-existing baseline. Browser Manual QA is now real and DOM-verified for Cases 1–6 (all PASS). Case 7 (responsive/viewport at 1366×768 and 390×844) could not be verified in this session because `resize_window` does not actually change the real viewport in this environment — this is flagged honestly as unverified, not claimed as PASS. Per task instruction, the hotfix commit is held pending the Product Owner's direct manual confirmation of Case 7. Not committed. Not pushed. Not deployed.
