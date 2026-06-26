# Phase 2.5 — Room Board UI Refinement

## 1. Objective

Four UI/UX improvements: dynamic extra-selection cards in Requirement Summary,
split Room Type Availability by in/out requirement, prominent section headers,
and save validation for out-of-requirement selections.

## 2. Files Changed

`resources/js/Pages/Admin/Bookings/Show.vue` — 1 file

## 3. Requirement Summary Improvements

- **Extra-selection cards**: When a user selects a room whose type is not in the
  booking requirements, a new card appears automatically with status
  `🟠 Chọn ngoài nhu cầu` and `X / 0` ratio. Card disappears when the
  extra room is deselected.
- Refactored `assignmentSummaryWithSelection` computed: extracted `buildSummaryCard`
  helper, appends cards for selected room types outside requirements.
- New computed: `hasExtraSelection`, `extraSelectionDetails` for save validation.
- Card styling for `extra` status uses the same orange theme as `over`.

## 4. Room Type Availability Improvements

- **Split into two groups**: "TRONG NHU CẦU" (left) and "NGOÀI NHU CẦU" (right).
- Uses `allRoomTypeSummaryDisplay` (all room types) instead of `roomTypeSummaryDisplay`
  (only required types).
- New computed: `requiredRoomTypeIds`, `availabilityInRequirement`,
  `availabilityOutsideRequirement`.
- Groups separated by a vertical border on desktop (`lg:border-l`), horizontal
  border on mobile (`border-t`).
- Each group uses the same compact card style.

## 5. Section Header Improvements

- **PHÂN PHÒNG**: Upgraded from `text-xs text-steel` to `text-sm font-bold` with
  a green accent strip (`h-5 w-1 bg-pine`) and bottom border (`border-b-2 border-pine/30`).
- **NHẬN PHÒNG - TRẢ PHÒNG**: New section header added above the stays table,
  using identical styling to PHÂN PHÒNG.

## 6. Save Warning Behavior

- When any room type outside the booking requirement is selected, `submitAssignment`
  shows a `window.confirm` dialog listing each extra type with its count.
- Dialog appears on every Save attempt (never remembered).
- Over-assignment warning preserved as fallback when no extra selection exists.

## 7. Tests Added/Updated

No new tests required — changes are UI-only (computed properties and template).
All existing tests continue to pass.

## 8. Test Results

240 passed, 0 failures (1679 assertions)

## 9. Ready for Manual QA

**YES**
