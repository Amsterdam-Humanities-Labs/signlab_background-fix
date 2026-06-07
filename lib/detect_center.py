#!/usr/bin/env python3
"""Minimal analysis for horizontal widening to 1.15:1:
  - left/right black-border crop (so a black edge doesn't skew centering / show)
  - person horizontal centre (background subtraction vs #316CA4), in CROPPED coords

Vertical is left untouched (no top/bottom crop, no scaling).
Prints JSON: {ok, w, h, crop_x, crop_w, cx}  (cx relative to the cropped width)."""
import sys, json

BG_BGR = (164, 108, 49)   # #316CA4 in BGR
FG_THRESH = 45            # colour distance from background -> foreground (person)
BLACK_THRESH = 26         # max channel below this -> black border pixel
COL_FRAC = 0.02

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "no path"})); return
    import cv2, numpy as np
    cap = cv2.VideoCapture(sys.argv[1])
    ret, frame = cap.read()
    cap.release()
    if not ret:
        print(json.dumps({"ok": False, "error": "no frame"})); return
    h, w = frame.shape[:2]

    # --- left/right black-border crop (full height; vertical untouched) ---
    bright = frame.max(axis=2)
    nonblack = (bright >= BLACK_THRESH).astype(np.uint8)
    nonblack = cv2.morphologyEx(nonblack, cv2.MORPH_OPEN, np.ones((5, 5), np.uint8))
    rows_any = nonblack.any(axis=1)
    if rows_any.any():
        first_col = np.argmax(nonblack, axis=1)
        last_col  = w - 1 - np.argmax(nonblack[:, ::-1], axis=1)
        cl = min(int(np.percentile(first_col[rows_any], 99)), w // 5)
        cr = max(int(np.percentile(last_col[rows_any], 1)), w - 1 - w // 5)
    else:
        cl, cr = 0, w - 1
    if cr <= cl:
        cl, cr = 0, w - 1
    cw = cr - cl + 1

    # --- person horizontal centre on the cropped frame ---
    sub = frame[:, cl:cr + 1]
    diff = np.abs(sub.astype(np.int16) - np.array(BG_BGR, dtype=np.int16))
    fg = (diff.max(axis=2) > FG_THRESH).astype(np.uint8)
    fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    cols = fg.sum(axis=0)
    occ = np.where(cols > h * COL_FRAC)[0]
    cx = (int(occ[0]) + int(occ[-1])) / 2 if occ.size else cw / 2

    print(json.dumps({"ok": True, "w": w, "h": h,
                      "crop_x": cl, "crop_w": cw, "cx": round(cx, 1)}))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
