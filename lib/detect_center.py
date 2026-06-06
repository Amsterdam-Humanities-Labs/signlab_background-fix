#!/usr/bin/env python3
"""Estimate the signer's horizontal center and head-top on the first usable frame.

The studio background is a uniform blue (#316CA4), so the foreground (person) is
simply the set of pixels that differ from that background. This is faster and more
robust for this footage than pose estimation. Prints JSON:
  {ok, w, h, cx, head_top}  in source pixels.
cx = horizontal center of the person, head_top = top-most foreground row.
Exit 0 always; ok=false if nothing detected."""
import sys, json

BG_BGR = (164, 108, 49)   # #316CA4 in BGR
DIFF_THRESH = 45          # per-pixel colour distance counted as foreground
COL_FRAC = 0.02           # a column/row counts as occupied if >2% of it is foreground

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "error": "no path"})); return
    import cv2, numpy as np
    cap = cv2.VideoCapture(sys.argv[1])
    out = {"ok": False}
    for _ in range(8):                       # skip rare blank lead frames
        ret, frame = cap.read()
        if not ret:
            break
        h, w = frame.shape[:2]
        diff = np.abs(frame.astype(np.int16) - np.array(BG_BGR, dtype=np.int16))
        fg = (diff.max(axis=2) > DIFF_THRESH).astype(np.uint8)
        # Denoise: keep pixels that have neighbours (3x3 erode then dilate).
        kernel = np.ones((3, 3), np.uint8)
        fg = cv2.morphologyEx(fg, cv2.MORPH_OPEN, kernel)
        cols = fg.sum(axis=0)
        rows = fg.sum(axis=1)
        occ_cols = np.where(cols > h * COL_FRAC)[0]
        occ_rows = np.where(rows > w * COL_FRAC)[0]
        if occ_cols.size == 0 or occ_rows.size == 0:
            continue
        x_min, x_max = int(occ_cols[0]), int(occ_cols[-1])
        y_top = int(occ_rows[0])
        out = {"ok": True, "w": w, "h": h,
               "cx": round((x_min + x_max) / 2, 1),
               "head_top": float(y_top),
               "x_min": x_min, "x_max": x_max}
        break
    cap.release()
    print(json.dumps(out))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(json.dumps({"ok": False, "error": str(e)}))
