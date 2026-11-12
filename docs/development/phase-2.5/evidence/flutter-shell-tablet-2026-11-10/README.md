# P2.5-FLUTTER-COMP — the bottom-nav shell at a second width, and the overflow it found

`Bottom-nav shell (DashBoardManagerScreen)` is a sixth surface beside the five `Nav destination`
rows: it is the chrome those five share. It could not honestly be claimed on one of their images —
the coverage-matrix generator refuses one picture for two surfaces, and it is right to — so the
shell gets a render of its own.

**A tablet is the honest second render**, not a second phone frame, because it answers something
the 360pt set cannot: `StylishBottomBar` lays five destinations out for a phone, and whether that
still reads at 768pt is a question about the shell rather than about any one destination.

## It did not read at 768pt

The first tablet pump produced **five overflows per frame, in all four combinations**:

```
A RenderFlex overflowed by 29 pixels on the bottom.     <- the selected destination
A RenderFlex overflowed by 3.3 pixels on the bottom.    <- x4, the others
```

Every size inside the bar was **width**-scaled — the icon `25.w` and the label `10.sp`, which
ScreenUtil also derives from the width — while the bar around them is **height**-scaled (`70.h`)
and `StylishBottomBar` fixes each `BottomBarItem`'s slot at 50 logical pixels of its own.

At the 360pt design width those three scales are all 1.0 and nothing disagrees, which is why five
waves of phone captures never showed it. At 768pt the width scale is 2.13 and the height scale is
1.28, so a 25pt icon became 53pt and a 10pt label became 21pt inside a slot that is still 50.

Repaired by tying both to the dimension that actually constrains them: `.h`. On a tablet the icon
becomes 32 and the label 12.8, which fit the 50 with room for the 1.2 line height.

**The five committed phone captures are unchanged byte for byte.** That is the proof the fix is a
no-op at the design size, and it was checked with `cmp` against
`evidence/flutter-shell-2026-11-09/after/` rather than assumed from the arithmetic — 8 of 8
identical.

| | |
|---|---|
| `flutter-shell-tablet_*` | 4 — 768×1024, all four combinations, after the repair |

The test asserts the surface really is the tablet one before capturing: `pinGoldenSurface` runs
inside `pumpScreen`, so passing the size and then photographing the wrong viewport would be
invisible in the image. It also fails on any exception, so a renewed overflow is a failing test
rather than a striped band in a picture nobody re-reads.
